<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payroll\PayrollPeriodLock;
use App\Models\Payroll\PayRun;
use App\Models\User;
use App\Services\DeviceResolver;
use App\Services\Payroll\TillPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollPeriodLockController extends Controller
{
    public function __construct(
        private readonly DeviceResolver $deviceResolver,
        private readonly TillPermissions $permissions,
    ) {}

    /**
     * A till claims a month for one pay run just before approving it
     * (payroll_api.dart). Only one run per business per month can hold the
     * claim, so two devices working offline can't both approve the same
     * payroll; PayrollSync refuses an approved regular run that doesn't
     * hold it. Claiming again for the same run succeeds.
     *
     * A reversal ('kind' = reversal) releases the reversed run's claim
     * ('releases_run_id'), freeing the month for a corrected run.
     *
     * user_id is the staff member whose PIN approved it, not necessarily
     * the device's paired account.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pay_run_id' => ['required', 'string'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'user_id' => ['required', 'string'],
            'kind' => ['nullable', 'in:regular,reversal'],
            'releases_run_id' => ['nullable', 'string'],
        ]);

        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }
        $businessId = (string) $device->tenant_id;

        $user = User::where('id', $data['user_id'])->where('business_id', $businessId)->first();
        if (! $this->permissions->userHas($user, $businessId, 'approvePayroll')) {
            return response()->json(['message' => 'This person is not allowed to approve payroll.'], 403);
        }

        $existingRun = PayRun::find($data['pay_run_id']);
        if ($existingRun && (string) $existingRun->business_id !== $businessId) {
            return response()->json(['message' => 'Pay run belongs to another business.'], 403);
        }

        return DB::transaction(function () use ($data, $businessId, $user) {
            $lock = PayrollPeriodLock::where('business_id', $businessId)
                ->where('period_year', $data['period_year'])
                ->where('period_month', $data['period_month'])
                ->lockForUpdate()
                ->first();

            if (($data['kind'] ?? 'regular') === 'reversal') {
                if ($lock && $lock->pay_run_id === ($data['releases_run_id'] ?? null)) {
                    $lock->delete();
                }

                return response()->json(['released' => true]);
            }

            if ($lock && $lock->pay_run_id !== $data['pay_run_id']) {
                $holder = PayRun::find($lock->pay_run_id);
                $label = $holder?->run_number ?? 'Another pay run';

                return response()->json([
                    'message' => "{$label} is already approved for this month. Reverse it before approving another.",
                ], 409);
            }

            $lock ??= PayrollPeriodLock::create([
                'business_id' => $businessId,
                'period_year' => $data['period_year'],
                'period_month' => $data['period_month'],
                'pay_run_id' => $data['pay_run_id'],
                'locked_by_user_id' => $user->id,
            ]);

            return response()->json(['locked' => true, 'pay_run_id' => $lock->pay_run_id]);
        });
    }
}
