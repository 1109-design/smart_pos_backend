<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\SyncRecord;
use App\Services\Accounting\PurchaseOrderApprovalGate;
use Illuminate\Support\Str;

/**
 * Generic approval queue used by every workflow that needs a supervisor
 * gate but may have no manager physically present to unlock it via PIN —
 * the till-side `requireApproval()` tries the PIN dialog first and only
 * falls back to this queue when nobody eligible is on site. Writes go
 * through SyncProcessor directly, same convergence pattern as
 * TransferService::syncUpsert(), so a BackOffice resolution and a
 * till-originated approval land through the identical code path.
 */
class ApprovalService
{
    public function __construct(private readonly SyncProcessor $processor) {}

    /**
     * @param  array<string, mixed>  $payload  Context needed to review/apply the action later.
     */
    public function request(
        string $businessId,
        string $subjectType,
        string $subjectId,
        string $action,
        string $requestedByUserId,
        array $payload = [],
    ): ApprovalRequest {
        $id = (string) Str::uuid();

        $this->syncUpsert($id, [
            'business_id' => $businessId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'requested_by_user_id' => $requestedByUserId,
            'status' => 'pending',
            'approver_user_id' => null,
            'approved_at' => null,
            'reason' => null,
            'payload_json' => $payload,
        ]);

        return ApprovalRequest::findOrFail($id);
    }

    public function resolve(string $id, string $approverUserId, string $decision, ?string $reason = null): ApprovalRequest
    {
        $request = ApprovalRequest::findOrFail($id);

        if (! $request->isPending()) {
            throw new \RuntimeException('This approval request has already been resolved.');
        }

        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \RuntimeException("Invalid decision: {$decision}");
        }

        $this->syncUpsert($request->id, [
            'business_id' => $request->business_id,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'action' => $request->action,
            'requested_by_user_id' => $request->requested_by_user_id,
            'status' => $decision,
            'approver_user_id' => $approverUserId,
            'approved_at' => now()->toIso8601String(),
            'reason' => $reason,
            'payload_json' => $request->payload_json,
        ]);

        if ($decision === 'approved') {
            $this->applyApprovedAction($request, $approverUserId);
        } else {
            $this->applyRejectedAction($request, $approverUserId);
        }

        return $request->fresh();
    }

    /**
     * Some actions are fully resolved server-side the moment BackOffice
     * approves them — an exchange-rate change just needs the row written,
     * no device execution required (unlike a void, which the till itself
     * replays on its next pull — see the Flutter sync engine's
     * `approval_requests` handling).
     *
     * PurchaseOrderApprovalGate is resolved lazily here (not constructor-
     * injected) because it depends on this class in the other direction
     * (raising a request calls back into ApprovalService::request()) —
     * constructor-injecting both ways is a circular dependency the
     * container can't satisfy.
     */
    private function applyApprovedAction(ApprovalRequest $request, string $approverUserId): void
    {
        if ($request->subject_type === 'PurchaseOrder' && $request->action === 'approve_purchase_order') {
            app(PurchaseOrderApprovalGate::class)->resolveApproved($request, $approverUserId);

            return;
        }

        if ($request->subject_type !== 'ExchangeRate' || $request->action !== 'change_exchange_rate') {
            return;
        }

        $payload = $request->payload_json ?? [];

        $this->processor->process('exchange_rates', $request->subject_id, 'upsert', [
            'business_id' => $request->business_id,
            'from_currency' => $payload['from_currency'] ?? null,
            'to_currency' => $payload['to_currency'] ?? null,
            'rate' => $payload['rate'] ?? null,
            'source' => 'manual',
            'set_by_user_id' => $approverUserId,
            'locked' => $payload['locked'] ?? false,
            'valid_from' => now()->toIso8601String(),
            'valid_until' => null,
        ]);

        SyncRecord::create([
            'business_id' => $request->business_id,
            'table_name' => 'exchange_rates',
            'record_uuid' => $request->subject_id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $request->business_id,
                'from_currency' => $payload['from_currency'] ?? null,
                'to_currency' => $payload['to_currency'] ?? null,
                'rate' => $payload['rate'] ?? null,
                'source' => 'manual',
                'set_by_user_id' => $approverUserId,
                'locked' => $payload['locked'] ?? false,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    /**
     * Mirrors applyApprovedAction() for the one subject type that also
     * needs to react to a rejection (a declined PO must fall back to
     * cancelled, not sit at pending_approval forever). Every other subject
     * type (void/refund/exchange-rate) needs no action on rejection — the
     * till already treats "no approval" as "stays as it was."
     */
    private function applyRejectedAction(ApprovalRequest $request, string $approverUserId): void
    {
        if ($request->subject_type !== 'PurchaseOrder' || $request->action !== 'approve_purchase_order') {
            return;
        }

        app(PurchaseOrderApprovalGate::class)->resolveRejected($request, $approverUserId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $uuid, array $payload): void
    {
        $this->processor->process('approval_requests', $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? null,
            'table_name' => 'approval_requests',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
