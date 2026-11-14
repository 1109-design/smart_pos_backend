<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraFiscalDay;
use App\Models\Zimra\ZimraSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One verdict per fiscal device, with the reasons behind it, so nobody has to
 * guess from silence that a device is fine. The tills show this verdict as it
 * is and never work out a "fine" of their own.
 *
 * Verdict: ok | warning | broken | unknown — the worst reason found.
 */
class FiscalDeviceHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const BROKEN = 'broken';

    public const UNKNOWN = 'unknown';

    private const RANK = [self::OK => 0, self::UNKNOWN => 1, self::WARNING => 2, self::BROKEN => 3];

    /**
     * Every device of [$businessId] with what the report needs, in a fixed
     * number of queries.
     *
     * @return Collection<int, array{device: ZimraDevice, current_day: ?ZimraFiscalDay, unresolved_days: Collection, pending_sales: int, failed_sales: int, health: array}>
     */
    public function forBusiness(string $businessId, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $devices = ZimraDevice::with('location')->where('business_id', $businessId)->orderBy('device_id')->get();
        $ids = $devices->pluck('device_id')->map(fn ($id) => (string) $id)->all();
        if ($ids === []) {
            return collect();
        }

        $latestDays = ZimraFiscalDay::whereIn('device_id', $ids)
            ->where('business_id', $businessId)
            ->orderByDesc('fiscal_day_no')
            ->get()
            ->groupBy('device_id');
        $unresolved = ZimraFiscalDay::whereIn('device_id', $ids)
            ->where('business_id', $businessId)
            ->unresolved()
            ->orderBy('fiscal_day_no')
            ->get()
            ->groupBy('device_id');
        $cfg = config('zimra.health');
        $pending = ZimraSale::whereIn('device_id', $ids)
            ->where('business_id', $businessId)
            ->whereIn('status', ['pending', 'retry'])
            ->where('created_at', '<', $now->copy()->subMinutes((int) $cfg['pending_sale_minutes']))
            ->selectRaw('device_id, count(*) as n')
            ->groupBy('device_id')
            ->pluck('n', 'device_id');
        $failed = ZimraSale::whereIn('device_id', $ids)
            ->where('business_id', $businessId)
            ->whereIn('status', ['failed', 'rejected'])
            ->where('created_at', '>=', $now->copy()->subDays((int) $cfg['problem_window_days']))
            ->selectRaw('device_id, count(*) as n')
            ->groupBy('device_id')
            ->pluck('n', 'device_id');

        return $devices->map(function (ZimraDevice $device) use ($latestDays, $unresolved, $pending, $failed, $now) {
            $id = (string) $device->device_id;
            $current = $latestDays->get($id)?->first();
            $open = $unresolved->get($id, collect());
            $pendingCount = (int) ($pending[$id] ?? 0);
            $failedCount = (int) ($failed[$id] ?? 0);

            return [
                'device' => $device,
                'current_day' => $current,
                'unresolved_days' => $open,
                'pending_sales' => $pendingCount,
                'failed_sales' => $failedCount,
                'health' => $this->assess($device, $current, $open, $pendingCount, $failedCount, $now),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, ZimraFiscalDay>  $unresolvedDays
     * @return array{verdict: string, reasons: list<array{code: string, severity: string, message: string}>}
     */
    public function assess(
        ZimraDevice $device,
        ?ZimraFiscalDay $currentDay,
        Collection $unresolvedDays,
        int $pendingSales,
        int $failedSales,
        CarbonInterface $now,
    ): array {
        $cfg = config('zimra.health');
        $reasons = [];
        $add = function (string $code, string $severity, string $message) use (&$reasons) {
            $reasons[] = compact('code', 'severity', 'message');
        };

        if (! $device->is_active) {
            $add('inactive', self::WARNING, 'This device is switched off for fiscalisation, so it sends nothing to ZIMRA.');
        }

        if ($device->status === 'error') {
            $add('device_error', self::BROKEN, 'The device is in an error state'.($device->error_message ? ': '.$device->error_message : '.'));
        }

        // Contact with ZIMRA.
        $lastOk = $device->last_contact_ok_at ?? $device->last_sync_at;
        if ($device->is_active) {
            if ($lastOk === null) {
                $add('never_contacted', self::UNKNOWN, 'No successful contact with ZIMRA has been recorded for this device yet.');
            } elseif ($lastOk->lt($now->copy()->subMinutes((int) $cfg['no_contact_minutes']))) {
                $add('no_contact', self::BROKEN, 'No successful contact with ZIMRA since '.$lastOk->copy()->setTimezone('Africa/Harare')->format('j M Y H:i').'. Sales may not be fiscalising.');
            }
        }
        if ($device->last_contact_failed_at !== null && ($lastOk === null || $device->last_contact_failed_at->gt($lastOk))) {
            $add('last_call_failed', self::WARNING, 'The last call to ZIMRA failed'.($device->last_contact_error ? ': '.$device->last_contact_error : '.'));
        }

        // The fiscal day.
        if ($currentDay !== null && $currentDay->status === ZimraFiscalDay::STATUS_OPEN && $currentDay->opened_at !== null) {
            $max = (int) ($device->fiscal_day_max_hours ?: 24);
            $hoursOpen = $currentDay->opened_at->diffInMinutes($now) / 60;
            if ($hoursOpen >= $max) {
                $add('day_overdue', self::BROKEN, "Fiscal day {$currentDay->fiscal_day_no} has been open longer than ZIMRA's {$max}-hour limit. It must be closed.");
            } elseif ($hoursOpen >= $max - (int) $cfg['day_warning_hours_before_limit']) {
                $add('day_near_limit', self::WARNING, "Fiscal day {$currentDay->fiscal_day_no} reaches ZIMRA's {$max}-hour limit soon.");
            }
        }
        foreach ($unresolvedDays as $day) {
            if ($day->requiresManualClose()) {
                $add('manual_close_required', self::BROKEN, "Fiscal day {$day->fiscal_day_no} must be closed in the ZIMRA Public Portal.");
            } elseif ($day->status === ZimraFiscalDay::STATUS_CLOSE_FAILED) {
                $add('close_failed', self::BROKEN, "ZIMRA rejected the close of fiscal day {$day->fiscal_day_no}".($day->error_message ? ': '.$day->error_message : '.'));
            } elseif ($day->status === ZimraFiscalDay::STATUS_CLOSE_SUBMITTED
                && $day->close_submitted_at?->lt($now->copy()->subMinutes((int) $cfg['close_unconfirmed_minutes']))) {
                $add('close_unconfirmed', self::WARNING, "ZIMRA hasn't confirmed the close of fiscal day {$day->fiscal_day_no} yet.");
            }
        }

        // The certificate.
        $expires = $device->certificate_expires_at;
        if ($expires === null) {
            $add('cert_unknown', self::WARNING, 'The device certificate expiry date is not known yet.');
        } elseif ($expires->lte($now)) {
            $add('cert_expired', self::BROKEN, 'The device certificate expired on '.$expires->format('j M Y').'. ZIMRA will refuse this device.');
        } elseif ($expires->lt($now->copy()->addDays((int) $cfg['certificate_warning_days']))) {
            $add('cert_expiring', self::WARNING, 'The device certificate expires on '.$expires->format('j M Y').'.');
        }

        // Sales the device owes ZIMRA.
        if ($failedSales > 0) {
            $add('failed_sales', self::BROKEN, "{$failedSales} ".($failedSales === 1 ? 'sale or invoice' : 'sales or invoices').' failed to fiscalise.');
        }
        if ($pendingSales > 0) {
            $add('pending_sales', self::WARNING, "{$pendingSales} ".($pendingSales === 1 ? 'sale or invoice is' : 'sales or invoices are').' still waiting to fiscalise.');
        }

        $verdict = self::OK;
        foreach ($reasons as $reason) {
            if (self::RANK[$reason['severity']] > self::RANK[$verdict]) {
                $verdict = $reason['severity'];
            }
        }

        return ['verdict' => $verdict, 'reasons' => $reasons];
    }
}
