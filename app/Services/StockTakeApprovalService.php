<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\SyncRecord;
use Illuminate\Support\Str;

/**
 * Server-side approve / reject / reopen of a stock take — shared by the
 * BackOffice stock take screen (StockTakesController) and by deciding the
 * take's 'stock_take_approval' request from the BackOffice Approvals inbox
 * (ApprovalService::applyApprovedAction), so both land on the same stock
 * movements and status. Mirrors the till's stock_take_approval_service.dart.
 *
 * Every write goes through SyncProcessor + a SyncRecord so devices pull it.
 */
class StockTakeApprovalService
{
    public const ACTION = 'stock_take_approval';

    public const SUBJECT_TYPE = 'StockTake';

    public function __construct(private readonly SyncProcessor $processor) {}

    /**
     * Brings each tracked line's on-hand to what was counted and marks the
     * take approved.
     *
     * @throws \RuntimeException when the take isn't awaiting approval or
     *                           still has lines flagged for recount
     */
    public function approve(StockTake $take, ?string $userId, ?string $comment = null): void
    {
        $take->loadMissing('items');

        // StockTake::isValidTransition() treats "same status" as a no-op it
        // always allows — by design, for idempotent syncs — so it will NOT
        // catch a second approve on an already-approved take. Without this
        // explicit check, approving twice would re-write every variance.
        if ($take->status !== 'pending_approval') {
            throw new \RuntimeException("{$take->title} is not awaiting approval.");
        }

        // STC·08 — a variance-threshold flag blocks approval until that item
        // has actually been recounted (StockTakeItem::needsRecount()).
        $pendingRecounts = $take->items->filter(fn ($item) => $item->needsRecount());
        if ($pendingRecounts->isNotEmpty()) {
            $names = $pendingRecounts->pluck('product_name')->implode(', ');
            throw new \RuntimeException("Recount required before approval: {$names}.");
        }

        $trackedProductIds = Product::whereIn('id', $take->items->pluck('product_id'))
            ->where('track_stock', true)
            ->pluck('id')
            ->all();

        foreach ($take->items as $item) {
            if (! in_array($item->product_id, $trackedProductIds, true)) {
                continue;
            }

            $counted = $item->counted_qty ?? $item->system_qty;

            // Reconcile against the CURRENT stock at approval time, not the
            // system_qty snapshot captured when the count started — stock can
            // move between then and approval (another device's push landing,
            // etc.), and a stock take must land on exactly what was
            // physically counted, not a stale delta stacked on top of
            // whatever the ledger has drifted to.
            $currentQty = $take->location_id
                ? (float) StockMovement::where('product_id', $item->product_id)
                    ->where('location_id', $take->location_id)
                    ->sum('quantity_change')
                : (float) (Product::find($item->product_id)?->stock_quantity ?? 0);

            $variance = (float) $counted - $currentQty;

            if (abs($variance) < 0.0001) {
                continue;
            }

            $this->recordVarianceMovement($take, $item->product_id, $variance, $userId);
        }

        $this->writeTake($take, [
            'status' => 'approved',
            'approved_by_user_id' => $userId,
            'approved_at' => now()->toIso8601String(),
            'review_comment' => $comment ?? $take->review_comment,
        ]);

        $this->closeLinkedRequests($take, 'approved', $userId, $comment);
    }

    /** No stock changes — the take is closed as rejected. */
    public function reject(StockTake $take, ?string $userId, ?string $comment = null): void
    {
        if ($take->status !== 'pending_approval') {
            throw new \RuntimeException("{$take->title} is not awaiting approval.");
        }

        $this->writeTake($take, [
            'status' => 'rejected',
            'review_comment' => $comment ?? $take->review_comment,
        ]);

        $this->closeLinkedRequests($take, 'rejected', $userId, $comment);
    }

    /**
     * "Send back for correction" — the counting team amends and resubmits,
     * which raises a fresh approval request for the corrected counts.
     */
    public function reopen(StockTake $take, ?string $userId): void
    {
        if ($take->status !== 'pending_approval') {
            throw new \RuntimeException("{$take->title} is not awaiting approval.");
        }

        $this->writeTake($take, ['status' => 'in_progress']);

        $this->closeLinkedRequests($take, 'rejected', $userId, 'Sent back for correction');
    }

    /**
     * Closes the take's still-pending inbox request(s) when the take is
     * decided on its own screen, so approvers aren't left with a stale item.
     * A request decided through ApprovalService is already non-pending by
     * the time this runs, so it's never touched twice.
     */
    private function closeLinkedRequests(StockTake $take, string $decision, ?string $userId, ?string $reason): void
    {
        $pending = ApprovalRequest::where('business_id', $take->business_id)
            ->where('subject_type', self::SUBJECT_TYPE)
            ->where('subject_id', $take->id)
            ->where('status', 'pending')
            ->get();

        foreach ($pending as $request) {
            // Full field set — SyncProcessor's approval_requests case is an
            // updateOrCreate() replace, so an omitted column resets.
            $payload = [
                'business_id' => $request->business_id,
                'subject_type' => $request->subject_type,
                'subject_id' => $request->subject_id,
                'action' => $request->action,
                'requested_by_user_id' => $request->requested_by_user_id,
                'status' => $decision,
                'approver_user_id' => $userId,
                'approved_at' => now()->toIso8601String(),
                'reason' => $reason,
                'payload_json' => $request->payload_json ?: (object) [],
                'rule_set_id' => $request->rule_set_id,
                'current_level' => $request->current_level,
                'max_level' => $request->max_level,
                'sla_due_at' => $request->sla_due_at?->toIso8601String(),
                'priority' => $request->priority,
                'estimated_value' => $request->estimated_value,
                'branch_id' => $request->branch_id,
            ];

            $this->sync('approval_requests', $request->id, $take->business_id, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function writeTake(StockTake $take, array $changes): void
    {
        $payload = array_merge([
            'business_id' => $take->business_id,
            'location_id' => $take->location_id,
            'title' => $take->title,
            'notes' => $take->notes,
            'created_by_user_id' => $take->created_by_user_id,
            'approved_by_user_id' => $take->approved_by_user_id,
            'approved_at' => $take->approved_at?->toIso8601String(),
            'review_comment' => $take->review_comment,
            'scope_type' => $take->scope_type,
            'scope_bin_ids' => $take->scope_bin_ids,
            'scope_label' => $take->scope_label,
        ], $changes);

        $this->sync('stock_takes', $take->id, $take->business_id, $payload);
    }

    private function recordVarianceMovement(StockTake $take, string $productId, float $variance, ?string $userId): void
    {
        $this->sync('stock_movements', (string) Str::uuid(), $take->business_id, [
            'business_id' => $take->business_id,
            'location_id' => $take->location_id,
            'product_id' => $productId,
            'type' => 'stocktake',
            'quantity_change' => $variance,
            'reason' => "Stock take: {$take->title}",
            'reference_id' => $take->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sync(string $table, string $uuid, ?string $businessId, array $payload): void
    {
        $this->processor->process($table, $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
