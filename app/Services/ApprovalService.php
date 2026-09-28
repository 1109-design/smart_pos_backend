<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRequestStageDecision;
use App\Models\ApprovalRule;
use App\Models\ExchangeRate;
use App\Models\SyncRecord;
use App\Services\Accounting\PurchaseOrderApprovalGate;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly SyncProcessor $processor,
        private readonly ApprovalRuleEngine $ruleEngine,
    ) {}

    /**
     * PHP has no distinct "empty map" type — json_decode('{}', true) and
     * json_decode('[]', true) both produce []. An empty $payload here would
     * then re-encode as a JSON *array* ('[]') everywhere it's embedded
     * (both the approval_requests.payload_json column, and the
     * sync_records.payload blob every device pulls), even though it
     * started life as an empty object. Every reader of this field — every
     * Flutter Approvals screen — decodes it expecting a JSON object and
     * throws on an array. Casting the empty case to a stdClass sidesteps
     * this: json_encode((object) []) always produces '{}', regardless of
     * nesting depth, unlike an empty array.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function jsonSafePayload(?array $payload): array|object|null
    {
        if ($payload === null) {
            return null;
        }

        return $payload === [] ? (object) [] : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload  Context needed to review/apply the action later.
     * @param  string|null  $process  ApprovalRuleSet::process key (e.g. 'purchase_order') to
     *                                route this request through the enterprise rule engine.
     *                                Optional and backward compatible — omitted, this behaves
     *                                exactly as before (no rule_set_id/SLA/required-role
     *                                attached, same as every caller until each is migrated).
     * @param  array<string, mixed>  $context  amount/percentage/quantity for condition
     *                                         evaluation — see ApprovalRuleEngine::evaluateCondition().
     */
    public function request(
        string $businessId,
        string $subjectType,
        string $subjectId,
        string $action,
        string $requestedByUserId,
        array $payload = [],
        ?string $process = null,
        array $context = [],
    ): ApprovalRequest {
        $id = (string) Str::uuid();

        $ruleFields = $process ? $this->resolveRuleFieldsForLevel($businessId, $process, $context, level: 1) : [];

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
            'payload_json' => $this->jsonSafePayload($payload),
            ...$ruleFields,
        ]);

        return ApprovalRequest::findOrFail($id);
    }

    /**
     * Finds the rule governing $level for $process (if any) and returns the
     * ApprovalRequest columns it drives — rule_set_id, current_level,
     * max_level, sla_due_at, estimated_value. Empty array when no enabled
     * rule set exists for the process, or no rule at that level matches the
     * given context (same "no rule = no gate" fallback the rest of this
     * class already assumes for every process not yet rule-driven).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveRuleFieldsForLevel(string $businessId, string $process, array $context, int $level): array
    {
        $ruleSet = $this->ruleEngine->resolveRuleSet($businessId, $process);

        if (! $ruleSet) {
            return [];
        }

        $rule = $this->ruleEngine->findApplicableRule($ruleSet, $context, $level);

        if (! $rule) {
            return [];
        }

        return [
            'rule_set_id' => $ruleSet->id,
            'current_level' => $level,
            'max_level' => (int) ApprovalRule::where('rule_set_id', $ruleSet->id)->max('level'),
            'sla_due_at' => now()->addHours($rule->sla_hours)->toIso8601String(),
            'estimated_value' => $context['amount'] ?? null,
        ];
    }

    /**
     * Wrapped in a transaction with a row lock on the request being
     * resolved: without it, two near-simultaneous calls for the same id (a
     * double-click, or two approvers/devices racing the same queued
     * request) can both read status='pending' before either writes, and
     * both proceed — duplicate ApprovalRequestStageDecision audit rows, and
     * for a subject type with a side effect (PO release, exchange-rate
     * write), a duplicate downstream action. `lockForUpdate()` serializes
     * them: the second caller blocks until the first transaction commits,
     * then sees the now-resolved status and takes the "already resolved"
     * path below instead. This also gives the decision + its side effect
     * the same all-or-nothing guarantee the till-side twin
     * (`resolveApprovalRequest` in approval_resolution.dart) already has —
     * a failure partway through must not leave the request marked resolved
     * with its side effect never applied, or vice versa.
     */
    public function resolve(string $id, string $approverUserId, string $decision, ?string $reason = null): ApprovalRequest
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new \RuntimeException("Invalid decision: {$decision}");
        }

        return DB::transaction(fn () => $this->resolveLocked($id, $approverUserId, $decision, $reason));
    }

    private function resolveLocked(string $id, string $approverUserId, string $decision, ?string $reason): ApprovalRequest
    {
        $request = ApprovalRequest::where('id', $id)->lockForUpdate()->firstOrFail();

        if (! $request->isPending()) {
            throw new \RuntimeException('This approval request has already been resolved.');
        }

        // Separation of duties (always) + group/role-based authority (only
        // when request() attached a rule_set_id — see resolveRuleFieldsForLevel()).
        // ApprovalsController::approve()/reject() only ever checked the
        // coarse MANAGE_APPROVALS permission ("can this person decide
        // approvals at all"), never whether *this* person is an eligible
        // decider for *this specific* request/stage — so any manager with
        // that permission could both resolve a request they themselves
        // raised, and clear a request a rule says needs a different named
        // approver. A request with no rule_set_id (every process not yet
        // routed through request()'s optional $process param) keeps today's
        // exact behaviour: any MANAGE_APPROVALS holder who isn't the requester.
        $rule = $request->rule_set_id
            ? $this->ruleEngine->findApplicableRule(
                $request->ruleSet,
                ['amount' => (float) ($request->estimated_value ?? 0)],
                $request->current_level,
            )
            : null;

        if (! $this->ruleEngine->canApprove($request->business_id, $approverUserId, $request, $rule)) {
            throw new \RuntimeException(
                match (true) {
                    $rule?->approval_group_id !== null => 'This stage requires a member of the assigned approver group to decide it.',
                    $rule?->required_role !== null => "This request requires {$rule->required_role} authority or higher to decide.",
                    default => 'You cannot approve or reject your own request.',
                }
            );
        }

        $delegatedFromUserId = $rule ? $this->ruleEngine->resolveDelegationSource($request->business_id, $approverUserId, $rule) : null;
        $slaBreached = $request->sla_due_at !== null && now()->greaterThan($request->sla_due_at);

        $priorStageDecision = ApprovalRequestStageDecision::where('approval_request_id', $request->id)
            ->where('level', $request->current_level - 1)
            ->latest('acted_at')
            ->first();
        $sameApproverAsPriorStage = $priorStageDecision !== null && $priorStageDecision->acted_by_user_id === $approverUserId;

        $this->recordStageDecision($request, $request->current_level, $decision, $approverUserId, $delegatedFromUserId, $reason, $slaBreached, $sameApproverAsPriorStage);

        // max_level is just the highest level *number* defined on the rule
        // set — for a conditional multi-level rule set (e.g. the seeded PO
        // rules, where level 2 only kicks in above $10,000) that number
        // means nothing on its own. Only advance if a rule actually applies
        // to THIS request's context at the next level; otherwise level 1
        // was always the last stage this specific request needed, and it's
        // final now, same as any single-level process.
        $nextRule = $decision === 'approved' && $request->current_level < $request->max_level
            ? $this->ruleEngine->findApplicableRule(
                $request->ruleSet,
                ['amount' => (float) ($request->estimated_value ?? 0)],
                $request->current_level + 1,
            )
            : null;
        $willAdvance = $nextRule !== null;

        $finalStatus = $willAdvance ? 'pending' : $decision;
        $nextLevel = $willAdvance ? $request->current_level + 1 : $request->current_level;
        $slaDueAt = $willAdvance ? now()->addHours($nextRule->sla_hours)->toIso8601String() : $request->sla_due_at?->toIso8601String();

        $this->syncUpsert($request->id, [
            'business_id' => $request->business_id,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'action' => $request->action,
            'requested_by_user_id' => $request->requested_by_user_id,
            'status' => $finalStatus,
            'approver_user_id' => $willAdvance ? null : $approverUserId,
            'approved_at' => $willAdvance ? null : now()->toIso8601String(),
            'reason' => $reason,
            'payload_json' => $this->jsonSafePayload($request->payload_json),
            'rule_set_id' => $request->rule_set_id,
            'current_level' => $nextLevel,
            'max_level' => $request->max_level,
            'sla_due_at' => $slaDueAt,
            'priority' => $request->priority,
            'estimated_value' => $request->estimated_value,
            'branch_id' => $request->branch_id,
            'is_delegated' => $delegatedFromUserId !== null,
            'delegated_from_user_id' => $delegatedFromUserId,
        ]);

        if ($willAdvance) {
            return $request->fresh();
        }

        if ($decision === 'approved') {
            $this->applyApprovedAction($request, $approverUserId);
        } else {
            $this->applyRejectedAction($request, $approverUserId);
        }

        return $request->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordStageDecision(
        ApprovalRequest $request,
        int $level,
        string $decision,
        string $approverUserId,
        ?string $delegatedFromUserId,
        ?string $reason,
        bool $slaBreached,
        bool $sameApproverAsPriorStage,
    ): void {
        $id = (string) Str::uuid();
        $payload = [
            'business_id' => $request->business_id,
            'approval_request_id' => $request->id,
            'level' => $level,
            'decision' => $decision,
            'acted_by_user_id' => $approverUserId,
            'acted_as_delegate_for_user_id' => $delegatedFromUserId,
            'reason' => $reason,
            'sla_breached' => $slaBreached,
            'same_approver_as_prior_stage' => $sameApproverAsPriorStage,
            'acted_at' => now()->toIso8601String(),
        ];

        $this->processor->process('approval_request_stage_decisions', $id, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $request->business_id,
            'table_name' => 'approval_request_stage_decisions',
            'record_uuid' => $id,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
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
        $now = now();

        // FX·06 — close out whatever row was "current" for this pair before
        // writing the new one, so exchange_rates keeps a real audit history
        // (valid_from/valid_until ranges) instead of every row but the
        // latest sitting with valid_until forever null. Full field set is
        // re-sent (not just valid_until) because SyncProcessor's upsert case
        // is a full updateOrCreate() replace — omitting a column would reset
        // it to that case's default (see exchange_rates case comment).
        $previous = ExchangeRate::query()
            ->where('business_id', $request->business_id)
            ->where('from_currency', $payload['from_currency'] ?? null)
            ->where('to_currency', $payload['to_currency'] ?? null)
            ->whereNull('valid_until')
            ->where('id', '!=', $request->subject_id)
            ->latest('valid_from')
            ->first();

        if ($previous) {
            $closedPayload = [
                'business_id' => $previous->business_id,
                'from_currency' => $previous->from_currency,
                'to_currency' => $previous->to_currency,
                'rate' => (float) $previous->rate,
                'source' => $previous->source,
                'set_by_user_id' => $previous->set_by_user_id,
                'locked' => $previous->locked,
                'valid_from' => $previous->valid_from?->toIso8601String(),
                'valid_until' => $now->toIso8601String(),
            ];

            $this->processor->process('exchange_rates', $previous->id, 'upsert', $closedPayload);

            SyncRecord::create([
                'business_id' => $previous->business_id,
                'table_name' => 'exchange_rates',
                'record_uuid' => $previous->id,
                'operation' => 'upsert',
                'payload' => $closedPayload,
                'source_updated_at' => $now,
                'synced_at' => $now,
            ]);
        }

        $this->processor->process('exchange_rates', $request->subject_id, 'upsert', [
            'business_id' => $request->business_id,
            'from_currency' => $payload['from_currency'] ?? null,
            'to_currency' => $payload['to_currency'] ?? null,
            'rate' => $payload['rate'] ?? null,
            'source' => 'manual',
            'set_by_user_id' => $approverUserId,
            'locked' => $payload['locked'] ?? false,
            'valid_from' => $now->toIso8601String(),
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
                'valid_from' => $now->toIso8601String(),
                'valid_until' => null,
            ],
            'source_updated_at' => $now,
            'synced_at' => $now,
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
