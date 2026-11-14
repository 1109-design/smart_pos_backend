<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraDeviceEvent;
use App\Models\Zimra\ZimraFiscalDay;
use App\Services\DeviceResolver;
use App\Services\Payroll\TillPermissions;
use App\Services\Zimra\FiscalDeviceHealth;
use App\Services\Zimra\ZimraClient;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fiscal Device Management for the tills (Finance → Fiscal Devices): each
 * ZIMRA device's health verdict, its fiscal days and its device log, plus a
 * "Check now" that asks ZIMRA for the device's live status and changes nothing.
 *
 * user_id is the person signed in on the till, checked for the
 * viewFiscalDevices till permission, as in PayrollPeriodLockController.
 * Responses are built field by field: certificates, keys, activation keys and
 * taxpayer config never leave the server.
 */
class ZimraDeviceController extends Controller
{
    /** Roles that hold viewFiscalDevices when their role row hasn't synced. */
    public const DEFAULT_ROLES = ['manager', 'branch_manager', 'operations_manager', 'finance_manager', 'accountant'];

    private const CHECK_EVERY_SECONDS = 30;

    public function __construct(
        private readonly DeviceResolver $deviceResolver,
        private readonly TillPermissions $permissions,
        private readonly FiscalDeviceHealth $health,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $auth = $this->authorise($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$businessId] = $auth;

        return response()->json([
            'server_time' => $this->iso(now()),
            'devices' => $this->health->forBusiness($businessId)->map(fn (array $row) => $this->devicePayload($row))->all(),
        ]);
    }

    public function fiscalDays(Request $request, string $deviceId): JsonResponse
    {
        $auth = $this->authorise($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$businessId] = $auth;
        $device = $this->findDevice($businessId, $deviceId);
        if ($device === null) {
            return response()->json(['message' => 'Fiscal device not found.'], 404);
        }

        $page = ZimraFiscalDay::where('business_id', $businessId)
            ->where('device_id', (string) $device->device_id)
            ->orderByDesc('fiscal_day_no')
            ->paginate(20);

        return response()->json([
            'server_time' => $this->iso(now()),
            'data' => collect($page->items())->map(fn (ZimraFiscalDay $day) => $this->dayPayload($day))->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function events(Request $request, string $deviceId): JsonResponse
    {
        $auth = $this->authorise($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$businessId] = $auth;
        $device = $this->findDevice($businessId, $deviceId);
        if ($device === null) {
            return response()->json(['message' => 'Fiscal device not found.'], 404);
        }

        $query = ZimraDeviceEvent::where('business_id', $businessId)
            ->where('device_id', (string) $device->device_id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
        if ($request->boolean('failures')) {
            $query->where('success', false);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', (string) $kind);
        }
        $page = $query->paginate(50);

        return response()->json([
            'server_time' => $this->iso(now()),
            'data' => collect($page->items())->map(fn (ZimraDeviceEvent $e) => [
                'id' => $e->id,
                'kind' => $e->kind,
                'source' => $e->source,
                'success' => $e->success,
                'guard_blocked' => $e->guard_blocked,
                'fiscal_day_no' => $e->fiscal_day_no,
                'http_status' => $e->http_status,
                'duration_ms' => $e->duration_ms,
                'summary' => $e->summary,
                'error' => $e->error,
                'occurred_at' => $this->iso($e->occurred_at),
            ])->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /**
     * Asks ZIMRA for the device's live status (GetStatus, read-only) and logs
     * it. Says so when ZIMRA's fiscal day differs from the server's record.
     */
    public function check(Request $request, string $deviceId, ZimraClient $client): JsonResponse
    {
        $auth = $this->authorise($request);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        [$businessId, $user] = $auth;
        $device = $this->findDevice($businessId, $deviceId);
        if ($device === null) {
            return response()->json(['message' => 'Fiscal device not found.'], 404);
        }

        $key = 'zimra-check:'.$device->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return response()->json([
                'message' => 'This device was just checked. Try again in a few seconds.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }
        RateLimiter::hit($key, self::CHECK_EVERY_SECONDS);

        $result = $client->setEventSource('manual', (string) $user->id)
            ->getStatus((string) $device->device_id, $device);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        $mismatch = null;
        if ($result['success']) {
            $zimraDayNo = ZimraFiscalDay::dayNoFromStatus($data);
            $zimraOpen = ($data['fiscalDayStatus'] ?? null) === 'FiscalDayOpened';
            $latest = ZimraFiscalDay::where('business_id', $businessId)
                ->where('device_id', (string) $device->device_id)
                ->orderByDesc('fiscal_day_no')
                ->first();
            $serverOpen = $latest?->status === ZimraFiscalDay::STATUS_OPEN;
            if ($latest !== null && $zimraDayNo > 0 && ($zimraDayNo !== $latest->fiscal_day_no || $zimraOpen !== $serverOpen)) {
                $mismatch = "ZIMRA says fiscal day {$zimraDayNo} is ".($zimraOpen ? 'open' : 'closed')
                    .", but the server's record has fiscal day {$latest->fiscal_day_no} ".($serverOpen ? 'open' : 'as '.str_replace('_', ' ', $latest->status)).'.';
            }
        }

        $row = $this->health->forBusiness($businessId)->first(fn (array $r) => $r['device']->is($device));

        return response()->json([
            'success' => (bool) $result['success'],
            'checked_at' => $this->iso(now()),
            'error' => $result['success'] ? null : ($result['error'] ?? 'ZIMRA did not answer.'),
            'zimra_status' => $result['success'] ? array_intersect_key($data, array_flip([
                'fiscalDayStatus', 'fiscalDayNo', 'lastFiscalDayNo', 'lastReceiptGlobalNo', 'fiscalDayClosed', 'fiscalDayClosingErrorCode',
            ])) : null,
            'mismatch' => $mismatch,
            'device' => $row ? $this->devicePayload($row) : null,
        ]);
    }

    /** @return array{0: string, 1: User}|JsonResponse */
    private function authorise(Request $request): array|JsonResponse
    {
        $device = $this->deviceResolver->fromRequest($request);
        if (! $device) {
            return response()->json(['message' => 'Device is not paired to a business.'], 403);
        }
        $businessId = (string) $device->tenant_id;

        $userId = (string) $request->input('user_id', '');
        $user = $userId === '' ? null : User::where('id', $userId)->where('business_id', $businessId)->first();
        if (! $this->permissions->userHas($user, $businessId, 'viewFiscalDevices', self::DEFAULT_ROLES)) {
            return response()->json(['message' => 'You are not allowed to view fiscal devices.'], 403);
        }

        return [$businessId, $user];
    }

    private function findDevice(string $businessId, string $deviceId): ?ZimraDevice
    {
        return ZimraDevice::where('business_id', $businessId)->where('device_id', $deviceId)->first();
    }

    /** @param array{device: ZimraDevice, current_day: ?ZimraFiscalDay, unresolved_days: mixed, pending_sales: int, failed_sales: int, health: array} $row */
    private function devicePayload(array $row): array
    {
        $device = $row['device'];

        return [
            'device_id' => (string) $device->device_id,
            'serial_no' => $device->device_serial_no,
            'model' => trim(($device->device_model_name ?? '').' '.($device->device_model_version ?? '')) ?: null,
            'location' => $device->location?->name,
            'status' => $device->status,
            'is_active' => (bool) $device->is_active,
            'last_contact_ok_at' => $this->iso($device->last_contact_ok_at ?? $device->last_sync_at),
            'last_contact_failed_at' => $this->iso($device->last_contact_failed_at),
            'last_contact_error' => $device->last_contact_error,
            'certificate_expires_at' => $this->iso($device->certificate_expires_at),
            'fiscal_day_max_hours' => $device->fiscal_day_max_hours,
            'current_fiscal_day' => $row['current_day'] ? $this->dayPayload($row['current_day']) : null,
            'pending_sales' => $row['pending_sales'],
            'failed_sales' => $row['failed_sales'],
            'health' => $row['health'],
        ];
    }

    private function dayPayload(ZimraFiscalDay $day): array
    {
        $attempts = is_array($day->close_attempts) ? $day->close_attempts : [];
        $last = $attempts === [] ? null : end($attempts);

        return [
            'fiscal_day_no' => $day->fiscal_day_no,
            'status' => $day->status,
            'opened_at' => $this->iso($day->opened_at),
            'close_submitted_at' => $this->iso($day->close_submitted_at),
            'closed_at' => $this->iso($day->closed_at),
            'receipt_counter' => $day->receipt_counter,
            'closing_error_code' => $day->closing_error_code,
            'error_message' => $day->error_message,
            'close_attempts' => count($attempts),
            'last_attempt_at' => is_array($last) ? ($last['at'] ?? null) : null,
            'requires_manual_close' => $day->requiresManualClose(),
        ];
    }

    private function iso(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->toIso8601ZuluString();
    }
}
