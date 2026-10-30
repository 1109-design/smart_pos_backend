<?php

namespace App\Services\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\ApprovalRequest;
use App\Services\ApprovalRuleEngine;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\DB;

/**
 * The 'journal_entry' approval process for BackOffice manual journals.
 * When a rule applies, the entry is saved as a draft and an
 * 'approve_journal_entry' request is raised; the final decision posts the
 * draft (approved) or discards it (rejected). Decisions arrive through
 * SyncProcessor's approval_requests case whether made in BackOffice
 * (ApprovalService::resolve routes through it) or synced from a device, so
 * this is applied exactly once, from there.
 */
class JournalEntryApprovalService
{
    public const PROCESS = 'journal_entry';

    public const ACTION = 'approve_journal_entry';

    public const SUBJECT_TYPE = 'JournalHeader';

    public function __construct(
        private readonly ApprovalRuleEngine $rules,
        private readonly JournalService $journals,
    ) {}

    /** Whether a manual journal of [$amount] needs approval before posting. */
    public function requiresApproval(string $businessId, float $amount): bool
    {
        $ruleSet = $this->rules->resolveRuleSet($businessId, self::PROCESS);

        return $ruleSet !== null
            && $this->rules->findApplicableRule($ruleSet, ['amount' => $amount], 1) !== null;
    }

    /** Raises the request for a draft journal awaiting approval. */
    public function requestApproval(JournalHeader $draft, string $requestedByUserId, float $amount): ApprovalRequest
    {
        return app(ApprovalService::class)->request(
            $draft->business_id,
            self::SUBJECT_TYPE,
            $draft->id,
            self::ACTION,
            $requestedByUserId,
            [
                'journal_number' => $draft->journal_number,
                'description' => $draft->description,
                'amount' => $amount,
            ],
            process: self::PROCESS,
            context: ['amount' => $amount],
        );
    }

    /** Posts or discards the draft once its request is finally decided. */
    public function applyDecision(ApprovalRequest $request): void
    {
        $draft = JournalHeader::where('id', $request->subject_id)
            ->where('business_id', $request->business_id)
            ->first();
        if ($draft === null || $draft->status !== 'draft') {
            return;
        }

        if ($request->status === 'approved') {
            $this->journals->post($draft, $request->approver_user_id);

            return;
        }

        DB::transaction(function () use ($draft) {
            $draft->lines()->delete();
            $draft->delete();
        });
    }
}
