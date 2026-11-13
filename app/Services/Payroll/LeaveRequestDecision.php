<?php

namespace App\Services\Payroll;

use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\Payroll\EmployeeLeaveEntry;
use App\Models\Payroll\EmployeeLeaveRequest;
use App\Models\SyncRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Applies the final Approvals decision to a leave request — the server
 * twin of leave_requests.dart's applyLeaveRequestDecision, used when the
 * decision is made in BackOffice (ApprovalService) or pushed by a till
 * (SyncProcessor's approval_requests case).
 *
 * Idempotent: only a still-pending request changes, and an approved annual
 * request's ledger row has a fixed id (UUIDv5 of the request id, the same
 * one the tills derive), so the deduction exists once however many times
 * — or on however many devices — the decision is applied.
 */
class LeaveRequestDecision
{
    public const SUBJECT_TYPE = 'LeaveRequest';

    public const ACTION = 'approve_leave_request';

    /** Inbox reason written when the request was cancelled, not rejected. */
    public const CANCELLED_REASON = 'Leave request cancelled';

    public static function ledgerEntryId(string $requestId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'smartpos:leave_request:'.$requestId)->toString();
    }

    public static function handles(ApprovalRequest $request): bool
    {
        return $request->subject_type === self::SUBJECT_TYPE && $request->action === self::ACTION;
    }

    /**
     * Why [$userId] may not decide [$request] — nobody decides their own
     * leave: not the requester, nor the employee's linked user when HR filed
     * it for them. Null = no objection.
     */
    public static function ownLeaveBlock(ApprovalRequest $request, ?string $userId): ?string
    {
        if (! self::handles($request) || $userId === null) {
            return null;
        }
        $leave = EmployeeLeaveRequest::find($request->subject_id);
        if ($leave === null) {
            return null;
        }
        if ((string) $leave->requested_by_user_id === $userId) {
            return 'You cannot decide a leave request you made.';
        }
        $employeeUser = Employee::where('id', $leave->employee_id)->value('user_id');
        if ($employeeUser !== null && (string) $employeeUser === $userId) {
            return 'You cannot decide your own leave.';
        }

        return null;
    }

    public function apply(ApprovalRequest $request, string $status, string $approverUserId): void
    {
        if (! self::handles($request) || ! in_array($status, ['approved', 'rejected'], true)) {
            return;
        }
        if ($status === 'rejected' && $request->reason === self::CANCELLED_REASON) {
            return; // closed because the request was cancelled
        }

        DB::transaction(function () use ($request, $status, $approverUserId) {
            $leave = EmployeeLeaveRequest::where('id', $request->subject_id)
                ->where('business_id', $request->business_id)
                ->lockForUpdate()
                ->first();
            if ($leave === null || $leave->status !== 'pending') {
                return;
            }

            $ledgerId = null;
            if ($status === 'approved' && $leave->leave_type === 'annual') {
                $ledgerId = self::ledgerEntryId($leave->id);
                if (! EmployeeLeaveEntry::whereKey($ledgerId)->exists()) {
                    $entry = EmployeeLeaveEntry::create([
                        'id' => $ledgerId,
                        'business_id' => $leave->business_id,
                        'employee_id' => $leave->employee_id,
                        'kind' => 'taken',
                        'days' => -1 * (float) $leave->days,
                        'entry_date' => $leave->start_date,
                        'notes' => 'Leave request '.$leave->start_date->toDateString().'–'.$leave->end_date->toDateString(),
                        'created_by_user_id' => $approverUserId,
                        'leave_request_id' => $leave->id,
                    ]);
                    $this->record($leave->business_id, 'employee_leave_entries', $ledgerId, [
                        'business_id' => $entry->business_id,
                        'employee_id' => $entry->employee_id,
                        'kind' => 'taken',
                        'days' => (float) $entry->days,
                        'entry_date' => self::iso($leave->start_date),
                        'pay_run_id' => null,
                        'notes' => $entry->notes,
                        'created_by_user_id' => $approverUserId,
                        'leave_request_id' => $leave->id,
                    ]);
                }
            }

            $leave->status = $status;
            $leave->decided_by_user_id = $approverUserId;
            $leave->decided_at = now();
            $leave->decision_note = $request->reason;
            if ($ledgerId !== null) {
                $leave->ledger_entry_id = $ledgerId;
            }
            $leave->save();

            $this->record($leave->business_id, 'employee_leave_requests', $leave->id, self::payload($leave));
        });
    }

    /**
     * Full row as the tills' payroll codec reads it (snake_case, ISO dates).
     *
     * @return array<string, mixed>
     */
    public static function payload(EmployeeLeaveRequest $leave): array
    {
        return [
            'id' => $leave->id,
            'business_id' => $leave->business_id,
            'employee_id' => $leave->employee_id,
            'employee_name' => $leave->employee_name,
            'leave_type' => $leave->leave_type,
            'start_date' => self::iso($leave->start_date),
            'end_date' => self::iso($leave->end_date),
            'half_day' => (bool) $leave->half_day,
            'days' => (float) $leave->days,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'requested_by_user_id' => $leave->requested_by_user_id,
            'decided_by_user_id' => $leave->decided_by_user_id,
            'decided_at' => self::iso($leave->decided_at),
            'decision_note' => $leave->decision_note,
            'cancelled_at' => self::iso($leave->cancelled_at),
            'ledger_entry_id' => $leave->ledger_entry_id,
            'created_at' => self::iso($leave->created_at),
            'updated_at' => self::iso($leave->updated_at),
        ];
    }

    private static function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->toIso8601ZuluString();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $businessId, string $table, string $uuid, array $payload): void
    {
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
