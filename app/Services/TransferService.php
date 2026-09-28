<?php

namespace App\Services;

use App\Models\Business;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\SyncRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    public function __construct(
        private readonly LocationService $stock,
        private readonly SyncProcessor $processor,
    ) {}

    /**
     * Create a new transfer request (status: pending, no stock movement yet).
     * Written through SyncProcessor so the request and its lines land in the
     * sync stream immediately — every device, including ones at neither
     * location, learns about it on their next pull.
     *
     * @param  array{business_id: string, from_location_id: string, to_location_id: string, requested_by_user_id: string, notes?: string, items: array<int, array{product_id: string, variant_id?: string|null, product_name: string, qty_requested: float}>}  $data
     */
    public function request(array $data): StockTransfer
    {
        return DB::transaction(function () use ($data) {
            $transferId = (string) Str::uuid();

            $this->syncUpsert('stock_transfers', $transferId, [
                'business_id' => $data['business_id'],
                'transfer_number' => $this->nextTransferNumber($data['business_id']),
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'requested_by_user_id' => $data['requested_by_user_id'],
                'approved_by_user_id' => null,
                'approved_at' => null,
                'dispatched_at' => null,
                'received_at' => null,
            ]);

            foreach ($data['items'] as $item) {
                $this->syncUpsert('stock_transfer_items', (string) Str::uuid(), [
                    'business_id' => $data['business_id'],
                    'stock_transfer_id' => $transferId,
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'product_name' => $item['product_name'],
                    'qty_requested' => $item['qty_requested'],
                    'qty_sent' => 0,
                    'qty_received' => 0,
                ]);
            }

            return StockTransfer::with('items')->findOrFail($transferId);
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

        $this->syncUpsert('stock_transfers', $transfer->id, $this->transferPayload($transfer, [
            'status' => 'approved',
            'approved_by_user_id' => $approvedByUserId,
            'approved_at' => now()->toIso8601String(),
        ]));

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

            if (! in_array($transfer->status, ['pending', 'approved'], true)) {
                throw new \RuntimeException("Transfer {$transfer->transfer_number} cannot be dispatched in its current status.");
            }

            // Opt-in gate: a business that has never configured this (or has
            // explicitly turned it off) keeps today's behavior — pending
            // dispatches straight through, no approval step required.
            $business = Business::find($transfer->business_id);
            if ($business?->workflowRequiresApproval('stock_transfer_requires_approval') && $transfer->status === 'pending') {
                throw new \RuntimeException("Transfer {$transfer->transfer_number} must be approved before it can be dispatched.");
            }

            $qtySentMap = collect($itemQtys)->keyBy('item_id');

            foreach ($transfer->items as $item) {
                $qtySent = (float) ($qtySentMap[$item->id]['qty_sent'] ?? $item->qty_requested);

                if ($qtySent <= 0) {
                    continue;
                }

                // Commit the stock being sent at the source (in_transit_quantity,
                // not reserved_quantity — that field is order-holds only) and
                // give the destination visibility of what's on its way before it
                // physically arrives. Neither is a ledger event yet (nothing has
                // moved), so both touch product_stock directly and are broadcast
                // by hand rather than via a stock_movement recompute.
                $this->stock->reserveInTransit($item->product_id, $transfer->from_location_id, $qtySent);
                $this->stock->publishStock($item->product_id, $transfer->from_location_id, $transfer->business_id);

                $this->stock->markIncoming($item->product_id, $transfer->to_location_id, $qtySent);
                $this->stock->publishStock($item->product_id, $transfer->to_location_id, $transfer->business_id);

                $this->syncUpsert('stock_transfer_items', $item->id, $this->itemPayload($item, $transfer->business_id, [
                    'qty_sent' => $qtySent,
                ]));
            }

            $this->syncUpsert('stock_transfers', $transfer->id, $this->transferPayload($transfer, [
                'status' => 'in_transit',
                'approved_by_user_id' => $transfer->approved_by_user_id ?? $dispatchedByUserId,
                'approved_at' => $transfer->approved_at?->toIso8601String() ?? now()->toIso8601String(),
                'dispatched_at' => now()->toIso8601String(),
            ]));

            return $transfer->fresh('items');
        });
    }

    /**
     * Receive: confirm quantities received, move stock from source to destination.
     * Every quantity change is written as a stock_movements row through
     * SyncProcessor, which recomputes both the per-location (product_stock) and
     * flat (products.stock_quantity) totals from the ledger and broadcasts the
     * corrected numbers to every device — the same path a till's own sync push
     * would take, so the web portal and the till agree on the result.
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
                $qtySent = (float) $item->qty_sent;
                $qtyReceived = min((float) ($qtyMap[$item->id]['qty_received'] ?? $qtySent), $qtySent);
                $shortfall = max(0.0, $qtySent - $qtyReceived);

                if ($qtySent <= 0) {
                    continue;
                }

                // Release the source's in-transit commitment and the destination's
                // incoming marker — the stock has now either physically arrived
                // (full or partial) or been lost in transit; either way it's no
                // longer "in transit" on either side.
                $this->stock->releaseInTransit($item->product_id, $transfer->from_location_id, $qtySent);
                $this->stock->clearIncoming($item->product_id, $transfer->to_location_id, $qtySent);

                $this->recordMovement($transfer, $item->product_id, 'transfer_out', $transfer->from_location_id, -$qtySent, $receivedByUserId, "Transfer {$transfer->transfer_number}", $transfer->to_location_id);

                if ($qtyReceived > 0) {
                    $this->recordMovement($transfer, $item->product_id, 'transfer_in', $transfer->to_location_id, $qtyReceived, $receivedByUserId, "Transfer {$transfer->transfer_number}");
                }

                if ($shortfall > 0) {
                    $this->recordMovement($transfer, $item->product_id, 'transfer_loss', $transfer->to_location_id, 0, $receivedByUserId, "Transfer {$transfer->transfer_number}: {$shortfall} short on receipt");
                }

                // in_transit_quantity at both ends just changed but isn't part of
                // the ledger recompute above — broadcast both explicitly.
                $this->stock->publishStock($item->product_id, $transfer->from_location_id, $transfer->business_id);
                $this->stock->publishStock($item->product_id, $transfer->to_location_id, $transfer->business_id);

                $this->syncUpsert('stock_transfer_items', $item->id, $this->itemPayload($item, $transfer->business_id, [
                    'qty_received' => $qtyReceived,
                ]));
            }

            $this->syncUpsert('stock_transfers', $transfer->id, $this->transferPayload($transfer, [
                'status' => 'received',
                'received_at' => now()->toIso8601String(),
            ]));

            return $transfer->fresh('items');
        });
    }

    /**
     * Cancel a transfer (pending or approved only — cannot cancel in_transit).
     * Releases any in-transit commitment that may have been set (defensive:
     * qty_sent is only ever set by dispatch(), which moves status to
     * in_transit and is therefore excluded by the guard above — but release
     * the correct field regardless of how that invariant might change).
     */
    public function cancel(string $transferId): StockTransfer
    {
        return DB::transaction(function () use ($transferId) {
            $transfer = StockTransfer::with('items')->lockForUpdate()->findOrFail($transferId);

            if (! in_array($transfer->status, ['pending', 'approved'], true)) {
                throw new \RuntimeException("Transfer {$transfer->transfer_number} cannot be cancelled once dispatched.");
            }

            foreach ($transfer->items as $item) {
                if ((float) $item->qty_sent > 0) {
                    $this->stock->releaseInTransit($item->product_id, $transfer->from_location_id, (float) $item->qty_sent);
                    $this->stock->clearIncoming($item->product_id, $transfer->to_location_id, (float) $item->qty_sent);
                    $this->stock->publishStock($item->product_id, $transfer->from_location_id, $transfer->business_id);
                    $this->stock->publishStock($item->product_id, $transfer->to_location_id, $transfer->business_id);
                }
            }

            $this->syncUpsert('stock_transfers', $transfer->id, $this->transferPayload($transfer, [
                'status' => 'cancelled',
            ]));

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

    /**
     * Full current-state payload for a stock_transfers row. SyncProcessor's
     * upsert handler replaces every column from the payload it's given, so
     * every call must carry the complete record, not just what changed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transferPayload(StockTransfer $transfer, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $transfer->business_id,
            'transfer_number' => $transfer->transfer_number,
            'from_location_id' => $transfer->from_location_id,
            'to_location_id' => $transfer->to_location_id,
            'status' => $transfer->status,
            'notes' => $transfer->notes,
            'requested_by_user_id' => $transfer->requested_by_user_id,
            'approved_by_user_id' => $transfer->approved_by_user_id,
            'approved_at' => $transfer->approved_at?->toIso8601String(),
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemPayload(StockTransferItem $item, string $businessId, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'stock_transfer_id' => $item->stock_transfer_id,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'product_name' => $item->product_name,
            'qty_requested' => (float) $item->qty_requested,
            'qty_sent' => (float) $item->qty_sent,
            'qty_received' => (float) $item->qty_received,
            'notes' => $item->notes,
        ], $overrides);
    }

    /**
     * Record one ledger entry and let SyncProcessor recompute + broadcast the
     * resulting per-location and flat stock totals.
     */
    private function recordMovement(
        StockTransfer $transfer,
        string $productId,
        string $type,
        string $locationId,
        float $quantityChange,
        string $userId,
        string $reason,
        ?string $toLocationId = null,
    ): void {
        $this->syncUpsert('stock_movements', (string) Str::uuid(), [
            'business_id' => $transfer->business_id,
            'location_id' => $locationId,
            'to_location_id' => $toLocationId,
            'product_id' => $productId,
            'type' => $type,
            'quantity_change' => $quantityChange,
            'reason' => $reason,
            'reference_id' => $transfer->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Apply a write through the same pipeline a device push uses, then publish
     * it to the sync stream so every device (this session's included) picks it
     * up on the next pull.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $table, string $uuid, array $payload): void
    {
        $this->processor->process($table, $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? null,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
