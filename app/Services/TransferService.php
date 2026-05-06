<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    public function __construct(private readonly LocationService $stock) {}

    /**
     * Create a new transfer request (status: pending, no stock movement yet).
     *
     * @param  array{business_id: string, from_location_id: string, to_location_id: string, requested_by_user_id: string, notes?: string, items: array<int, array{product_id: string, variant_id?: string|null, product_name: string, qty_requested: float}>}  $data
     */
    public function request(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data) {
            $transfer = StockTransfer::create([
                'id'                    => Str::uuid(),
                'business_id'           => $data['business_id'],
                'transfer_number'       => $this->nextTransferNumber($data['business_id']),
                'from_location_id'      => $data['from_location_id'],
                'to_location_id'        => $data['to_location_id'],
                'status'                => 'pending',
                'notes'                 => $data['notes'] ?? null,
                'requested_by_user_id'  => $data['requested_by_user_id'],
            ]);

            foreach ($data['items'] as $item) {
                StockTransferItem::create([
                    'id'               => Str::uuid(),
                    'stock_transfer_id' => $transfer->id,
                    'product_id'       => $item['product_id'],
                    'variant_id'       => $item['variant_id'] ?? null,
                    'product_name'     => $item['product_name'],
                    'qty_requested'    => $item['qty_requested'],
                    'qty_sent'         => 0,
                    'qty_received'     => 0,
                ]);
            }

            return $transfer->load('items');
        });
    }

    /**
     * Approve a pending transfer (warehouse confirms it will fulfil the request).
     */
    public function approve(string $transferId, string $approvedByUserId): StockTransfer
    {
        $transfer = StockTransfer::with('items')->findOrFail($transferId);

        if ($transfer->status !== 'pending') {
            throw new \RuntimeException("Transfer {$transfer->transfer_number} is not in pending status.");
        }

        $transfer->update([
            'status'               => 'approved',
            'approved_by_user_id'  => $approvedByUserId,
            'approved_at'          => now(),
        ]);

        return $transfer->fresh('items');
    }

    /**
     * Dispatch: reserve stock at source and mark transfer as in_transit.
     * qty_sent may differ from qty_requested (partial dispatch is allowed).
     *
     * @param  array<int, array{item_id: string, qty_sent: float}>  $itemQtys
     */
    public function dispatch(string $transferId, array $itemQtys, string $dispatchedByUserId): StockTransfer
    {
        return DB::transaction(function () use ($transferId, $itemQtys, $dispatchedByUserId) {
            $transfer = StockTransfer::with('items')->lockForUpdate()->findOrFail($transferId);

            if (! in_array($transfer->status, ['pending', 'approved'])) {
                throw new \RuntimeException("Transfer {$transfer->transfer_number} cannot be dispatched in its current status.");
            }

            $qtySentMap = collect($itemQtys)->keyBy('item_id');

            foreach ($transfer->items as $item) {
                $qtySent = (float) ($qtySentMap[$item->id]['qty_sent'] ?? $item->qty_requested);

                if ($qtySent <= 0) {
                    continue;
                }

                // Reserve the stock being sent at the source location
                $this->stock->reserveStock($item->product_id, $transfer->from_location_id, $qtySent);

                $item->update(['qty_sent' => $qtySent]);
            }

            $transfer->update([
                'status'                => 'in_transit',
                'approved_by_user_id'  => $transfer->approved_by_user_id ?? $dispatchedByUserId,
                'approved_at'          => $transfer->approved_at ?? now(),
                'dispatched_at'        => now(),
            ]);

            return $transfer->fresh('items');
        });
    }

    /**
     * Receive: confirm quantities received, move stock from source to destination.
     * Creates stock_movement records for audit trail.
     *
     * @param  array<int, array{item_id: string, qty_received: float}>  $receivedQtys
     */
    public function receive(string $transferId, array $receivedQtys, string $receivedByUserId): StockTransfer
    {
        return DB::transaction(function () use ($transferId, $receivedQtys, $receivedByUserId) {
            $transfer = StockTransfer::with('items')->lockForUpdate()->findOrFail($transferId);

            if ($transfer->status !== 'in_transit') {
                throw new \RuntimeException("Transfer {$transfer->transfer_number} is not in transit.");
            }

            $qtyMap = collect($receivedQtys)->keyBy('item_id');

            foreach ($transfer->items as $item) {
                $qtyReceived = (float) ($qtyMap[$item->id]['qty_received'] ?? $item->qty_sent);
                $qtyReceived = min($qtyReceived, (float) $item->qty_sent);

                if ($qtyReceived <= 0) {
                    continue;
                }

                // Deduct from source (also releases the reservation)
                $this->stock->releaseReservation($item->product_id, $transfer->from_location_id, (float) $item->qty_sent);
                $this->stock->deductStock($item->product_id, $transfer->from_location_id, $qtyReceived);

                // Add to destination
                $this->stock->addStock($item->product_id, $transfer->to_location_id, $qtyReceived);

                // Audit trail at source
                StockMovement::create([
                    'id'              => Str::uuid(),
                    'business_id'     => $transfer->business_id,
                    'location_id'     => $transfer->from_location_id,
                    'to_location_id'  => $transfer->to_location_id,
                    'product_id'      => $item->product_id,
                    'type'            => 'transfer_out',
                    'quantity_change' => -$qtyReceived,
                    'reason'          => "Transfer {$transfer->transfer_number}",
                    'reference_id'    => $transfer->id,
                    'user_id'         => $receivedByUserId,
                ]);

                // Audit trail at destination
                StockMovement::create([
                    'id'              => Str::uuid(),
                    'business_id'     => $transfer->business_id,
                    'location_id'     => $transfer->to_location_id,
                    'to_location_id'  => null,
                    'product_id'      => $item->product_id,
                    'type'            => 'transfer_in',
                    'quantity_change' => $qtyReceived,
                    'reason'          => "Transfer {$transfer->transfer_number}",
                    'reference_id'    => $transfer->id,
                    'user_id'         => $receivedByUserId,
                ]);

                $item->update(['qty_received' => $qtyReceived]);
            }

            $transfer->update([
                'status'      => 'received',
                'received_at' => now(),
            ]);

            return $transfer->fresh('items');
        });
    }

    /**
     * Cancel a transfer (pending or approved only — cannot cancel in_transit).
     * Releases any reservations that may have been set.
     */
    public function cancel(string $transferId): StockTransfer
    {
        return DB::transaction(function () use ($transferId) {
            $transfer = StockTransfer::with('items')->lockForUpdate()->findOrFail($transferId);

            if (! in_array($transfer->status, ['pending', 'approved'])) {
                throw new \RuntimeException("Transfer {$transfer->transfer_number} cannot be cancelled once dispatched.");
            }

            // Release any reservations
            foreach ($transfer->items as $item) {
                if ((float) $item->qty_sent > 0) {
                    $this->stock->releaseReservation($item->product_id, $transfer->from_location_id, (float) $item->qty_sent);
                }
            }

            $transfer->update(['status' => 'cancelled']);

            return $transfer->fresh('items');
        });
    }

    private function nextTransferNumber(string $businessId): string
    {
        $prefix = 'TRF-'.now()->format('Ym');
        $count = StockTransfer::where('business_id', $businessId)
            ->where('transfer_number', 'like', "{$prefix}-%")
            ->count();

        return sprintf('%s-%03d', $prefix, $count + 1);
    }
}
