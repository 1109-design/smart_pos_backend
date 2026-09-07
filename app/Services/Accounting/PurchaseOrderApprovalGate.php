<?php

namespace App\Services\Accounting;

use App\Models\ApprovalRequest;
use App\Models\PoAuditLog;
use App\Models\PurchaseOrder;
use App\Models\SyncRecord;
use App\Models\User;
use App\Services\ApprovalService;

/**
 * Purchasing & Cash Vault Blueprint, part D — the till-raised half of "POs
 * over $X require owner approval." SyncProcessor::gatePurchaseOrderStatus()
 * decides WHEN a PO needs gating (a till-authored action, same shape as the
 * void/refund flows ApprovalService already bridges to a remote review);
 * this class is the OTHER half: raising the request, and — once an
 * owner/manager decides on the BackOffice Approvals page — applying that
 * decision straight to the PurchaseOrder row.
 *
 * Deliberately bypasses SyncProcessor's purchase_orders case entirely when
 * applying a decision (a direct Eloquent update + a hand-written
 * SyncRecord, same trick used for the transactions.created_at fix
 * elsewhere in this codebase) rather than routing back through
 * process('purchase_orders', ...) — doing that would re-enter the same gate
 * this class exists to resolve, which is exactly the kind of self-locking
 * bug this session has hit before with sync convergence code.
 */
class PurchaseOrderApprovalGate
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function requestApproval(string $poId, ?string $reason = null): void
    {
        $po = PurchaseOrder::find($poId);

        if (! $po || ! $po->created_by_user_id) {
            return;
        }

        $reason ??= "total exceeds this business's configured PO threshold";

        $this->approvals->request(
            $po->business_id,
            'PurchaseOrder',
            $po->id,
            'approve_purchase_order',
            $po->created_by_user_id,
            [
                'po_number' => $po->po_number,
                'supplier_name' => $po->supplier_name,
                'total_ordered' => (float) $po->total_ordered,
                'reason' => $reason,
            ],
        );

        // po_audit_logs.user_id is a required uuid with no "system actor" —
        // attributed to the PO's own creator, since the system-triggered
        // hold is a direct consequence of their submission, not an
        // independent action by anyone else.
        PoAuditLog::create([
            'po_id' => $po->id,
            'user_id' => $po->created_by_user_id,
            'user_name' => 'System',
            'action' => 'pending_approval',
            'note' => "Held for approval — {$reason}.",
        ]);
    }

    public function resolveApproved(ApprovalRequest $request, string $approverUserId): void
    {
        $this->applyDecision($request, $approverUserId, 'sent', 'approved');
    }

    public function resolveRejected(ApprovalRequest $request, string $approverUserId): void
    {
        $this->applyDecision($request, $approverUserId, 'cancelled', 'rejected');
    }

    private function applyDecision(ApprovalRequest $request, string $approverUserId, string $newStatus, string $auditAction): void
    {
        $po = PurchaseOrder::find($request->subject_id);

        if (! $po || $po->status !== 'pending_approval') {
            return;
        }

        $po->update(['status' => $newStatus]);

        SyncRecord::create([
            'business_id' => $po->business_id,
            'table_name' => 'purchase_orders',
            'record_uuid' => $po->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $po->business_id,
                'receiving_location_id' => $po->receiving_location_id,
                'supplier_id' => $po->supplier_id,
                'supplier_name' => $po->supplier_name,
                'po_number' => $po->po_number,
                'status' => $newStatus,
                'total_ordered' => (float) $po->total_ordered,
                'total_received' => (float) $po->total_received,
                'notes' => $po->notes,
                'expected_date' => $po->expected_date?->toIso8601String(),
                'additional_costs_json' => $po->additional_costs_json,
                'created_by_user_id' => $po->created_by_user_id,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        PoAuditLog::create([
            'po_id' => $po->id,
            'user_id' => $approverUserId,
            'user_name' => User::find($approverUserId)?->name ?? 'Unknown',
            'action' => $auditAction,
            'note' => $auditAction === 'approved'
                ? 'Approval granted — order released to supplier.'
                : 'Approval declined — order cancelled.',
        ]);
    }
}
