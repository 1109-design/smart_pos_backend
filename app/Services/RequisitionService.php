<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Requisition;
use App\Models\SyncRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * STK·03 — request → approve → issue, the same three-stage shape
 * StockTransfer already proved out, but simpler: a requisition only ever
 * debits one location (no in-transit/reserved two-location dance), because
 * "general use" and "issued to a project" both just consume stock rather
 * than moving it somewhere else that also needs to see it arrive.
 *
 * Approval here is a direct BackOffice permission check (mirrors
 * TransferService::approve()/StockTakesController::approve()), not the
 * generic ApprovalService queue — that queue exists specifically to bridge
 * a till-raised action to either an on-site PIN or a remote BackOffice
 * review, and a requisition is BackOffice-authored end to end today (see
 * the class docblock on RequisitionsController for the till-side gap this
 * leaves, and why it's an acceptable scope cut for now).
 */
class RequisitionService
{
    public function __construct(private readonly SyncProcessor $processor) {}

    /**
     * @param  array{business_id: string, location_id: string, requested_by_user_id: string, purpose?: string, project_id?: string|null, notes?: string|null, items: array<int, array{product_id: string, product_name: string, quantity_requested: float}>}  $data
     */
    public function request(array $data): Requisition
    {
        return DB::transaction(function () use ($data) {
            $requisitionId = (string) Str::uuid();

            $this->syncUpsert('requisitions', $requisitionId, [
                'business_id' => $data['business_id'],
                'requisition_number' => $this->nextRequisitionNumber($data['business_id']),
                'location_id' => $data['location_id'],
                'purpose' => $data['purpose'] ?? 'general',
                'project_id' => $data['project_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'requested_by_user_id' => $data['requested_by_user_id'],
                'approved_by_user_id' => null,
                'approved_at' => null,
                'issued_by_user_id' => null,
                'issued_at' => null,
            ]);

            foreach ($data['items'] as $item) {
                $this->syncUpsert('requisition_items', (string) Str::uuid(), [
                    'business_id' => $data['business_id'],
                    'requisition_id' => $requisitionId,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'quantity_requested' => $item['quantity_requested'],
                    'quantity_issued' => 0,
                ]);
            }

            return Requisition::with('items')->findOrFail($requisitionId);
        });
    }

    public function approve(string $requisitionId, string $approvedByUserId): Requisition
    {
        $requisition = Requisition::findOrFail($requisitionId);

        if (! $requisition->isPending()) {
            throw new \RuntimeException("Requisition {$requisition->requisition_number} is not in pending status.");
        }

        $this->syncUpsert('requisitions', $requisition->id, $this->requisitionPayload($requisition, [
            'status' => 'approved',
            'approved_by_user_id' => $approvedByUserId,
            'approved_at' => now()->toIso8601String(),
        ]));

        return $requisition->fresh();
    }

    public function reject(string $requisitionId, string $rejectedByUserId): Requisition
    {
        $requisition = Requisition::findOrFail($requisitionId);

        if (! $requisition->isPending()) {
            throw new \RuntimeException("Requisition {$requisition->requisition_number} is not in pending status.");
        }

        $this->syncUpsert('requisitions', $requisition->id, $this->requisitionPayload($requisition, [
            'status' => 'rejected',
            'approved_by_user_id' => $rejectedByUserId,
            'approved_at' => now()->toIso8601String(),
        ]));

        return $requisition->fresh();
    }

    /**
     * Issue: post the stock_movements debit for each item (SyncProcessor
     * recomputes product_stock/products.stock_quantity from the ledger and
     * broadcasts the result — same as every other stock-mutating flow in
     * this app) and mark the requisition (and each item) issued. This IS
     * the "Stock Issue Note" the spec asks for — the requisition's own
     * request → approve → issue trail, not a separate document.
     *
     * @param  array<int, array{item_id: string, quantity_issued: float}>  $itemQtys
     */
    public function issue(string $requisitionId, array $itemQtys, string $issuedByUserId): Requisition
    {
        return DB::transaction(function () use ($requisitionId, $itemQtys, $issuedByUserId) {
            $requisition = Requisition::with('items')->lockForUpdate()->findOrFail($requisitionId);

            if (! $requisition->isApproved()) {
                throw new \RuntimeException("Requisition {$requisition->requisition_number} must be approved before it can be issued.");
            }

            $qtyMap = collect($itemQtys)->keyBy('item_id');

            foreach ($requisition->items as $item) {
                $qtyIssued = (float) ($qtyMap[$item->id]['quantity_issued'] ?? $item->quantity_requested);
                $qtyIssued = min($qtyIssued, (float) $item->quantity_requested);

                if ($qtyIssued <= 0) {
                    continue;
                }

                // Snapshot the cost basis at the moment stock actually
                // leaves — PRJ·04's cost build-up report reads this back
                // rather than whatever products.cost_price is later, which
                // could have moved since.
                $unitCost = (float) (Product::find($item->product_id)?->cost_price ?? 0);

                $this->syncUpsert('stock_movements', (string) Str::uuid(), [
                    'business_id' => $requisition->business_id,
                    'location_id' => $requisition->location_id,
                    'product_id' => $item->product_id,
                    'type' => 'requisition_issue',
                    'quantity_change' => -$qtyIssued,
                    'unit_cost' => $unitCost,
                    'reason' => $this->issueReason($requisition),
                    'reference_id' => $requisition->id,
                    'user_id' => $issuedByUserId,
                ]);

                $this->syncUpsert('requisition_items', $item->id, [
                    'business_id' => $requisition->business_id,
                    'requisition_id' => $requisition->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity_requested' => (float) $item->quantity_requested,
                    'quantity_issued' => $qtyIssued,
                    'unit_cost' => $unitCost,
                ]);
            }

            $this->syncUpsert('requisitions', $requisition->id, $this->requisitionPayload($requisition, [
                'status' => 'issued',
                'issued_by_user_id' => $issuedByUserId,
                'issued_at' => now()->toIso8601String(),
            ]));

            return $requisition->fresh('items');
        });
    }

    public function cancel(string $requisitionId): Requisition
    {
        $requisition = Requisition::findOrFail($requisitionId);

        if (! in_array($requisition->status, ['pending', 'approved'], true)) {
            throw new \RuntimeException("Requisition {$requisition->requisition_number} cannot be cancelled once issued.");
        }

        $this->syncUpsert('requisitions', $requisition->id, $this->requisitionPayload($requisition, [
            'status' => 'cancelled',
        ]));

        return $requisition->fresh();
    }

    private function issueReason(Requisition $requisition): string
    {
        return $requisition->purpose === 'project' && $requisition->project_id
            ? "Requisition {$requisition->requisition_number}: project {$requisition->project_id}"
            : "Requisition {$requisition->requisition_number}: general use";
    }

    private function nextRequisitionNumber(string $businessId): string
    {
        $prefix = 'REQ-'.now()->format('Ym');
        $count = Requisition::where('business_id', $businessId)
            ->where('requisition_number', 'like', "{$prefix}-%")
            ->count();

        return sprintf('%s-%03d', $prefix, $count + 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function requisitionPayload(Requisition $requisition, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $requisition->business_id,
            'requisition_number' => $requisition->requisition_number,
            'location_id' => $requisition->location_id,
            'purpose' => $requisition->purpose,
            'project_id' => $requisition->project_id,
            'notes' => $requisition->notes,
            'status' => $requisition->status,
            'requested_by_user_id' => $requisition->requested_by_user_id,
            'approved_by_user_id' => $requisition->approved_by_user_id,
            'approved_at' => $requisition->approved_at?->toIso8601String(),
            'issued_by_user_id' => $requisition->issued_by_user_id,
            'issued_at' => $requisition->issued_at?->toIso8601String(),
        ], $overrides);
    }

    /**
     * Apply a write through the same pipeline a device push uses, then
     * publish it to the sync stream — same pattern as
     * TransferService::syncUpsert(). No till today creates or reads a
     * requisition (see RequisitionsController's docblock), but registering
     * these tables in SyncProcessor now means a future till screen is a
     * pure UI addition, not a backend one, and the resulting
     * `stock_movements` rows from issue() reach every device exactly like
     * any other stock-mutating flow already does.
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
