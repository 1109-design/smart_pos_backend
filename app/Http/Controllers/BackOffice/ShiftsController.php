<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Location;
use App\Models\Shift;
use App\Services\BackOfficeAuthorizer;
use App\Services\LocationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftsController extends BackOfficeController
{
    public function __invoke(Request $request, LocationService $locationService, BackOfficeAuthorizer $authorizer): Response
    {
        $session = session('backoffice');
        $currency = $session['currency_code'] ?? 'USD';
        $tenantId = $this->tenantId();
        $scope = $authorizer->currentLocationScope();

        $locationService->ensureDefaultLocation($tenantId);

        $from = $request->date('from', 'Y-m-d') ?? now()->subDays(6)->toDateString();
        $to = $request->date('to', 'Y-m-d') ?? now()->toDateString();
        $status = $request->string('status')->toString() ?: 'all';
        $locationId = $request->string('location')->toString() ?: 'all';

        // A scoped user's "all" means "all locations I can see," not every
        // branch — and a location outside their scope (guessed in the URL)
        // clamps back to 'all' (i.e. their own scope) rather than leaking
        // data or echoing back a filter value the location dropdown doesn't
        // even offer them.
        if ($scope !== null && $locationId !== 'all' && ! in_array($locationId, $scope, true)) {
            $locationId = 'all';
        }

        // effectiveLocationIds is null only when there's truly no filter to
        // apply (unscoped user asking for "all").
        $effectiveLocationIds = match (true) {
            $locationId !== 'all' => [$locationId],
            $scope !== null => $scope,
            default => null,
        };

        $fromStart = Carbon::parse($from)->startOfDay();
        $toEnd = Carbon::parse($to)->endOfDay();

        // ── Currently open shifts (live, regardless of the date filter) ──
        // This is the whole point: let the owner see who is on till right
        // now, at which location, and their cash position, without walking
        // up to a device. Each shift carries the location_id the till had
        // set when it synced, so this is a straight read, not a guess.
        $openShifts = Shift::where('shifts.business_id', $tenantId)
            ->where('shifts.status', 'open')
            ->leftJoin('users', 'shifts.cashier_id', '=', 'users.id')
            ->leftJoin('locations', 'shifts.location_id', '=', 'locations.id')
            ->when($effectiveLocationIds !== null, fn ($query) => $query->whereIn('shifts.location_id', $effectiveLocationIds))
            ->orderBy('shifts.opened_at')
            ->get([
                'shifts.id', 'shifts.opened_at', 'shifts.opening_float',
                'shifts.total_sales', 'shifts.cash_sales', 'shifts.transaction_count',
                'users.name as cashier_name', 'locations.name as location_name',
            ]);

        // ── Shift history for the selected range ──────────────────────
        $shifts = Shift::query()
            ->where('shifts.business_id', $tenantId)
            ->leftJoin('users', 'shifts.cashier_id', '=', 'users.id')
            ->leftJoin('locations', 'shifts.location_id', '=', 'locations.id')
            ->whereBetween('shifts.opened_at', [$fromStart, $toEnd])
            ->when($status !== 'all', fn ($query) => $query->where('shifts.status', $status))
            ->when($effectiveLocationIds !== null, fn ($query) => $query->whereIn('shifts.location_id', $effectiveLocationIds))
            ->orderByDesc('shifts.opened_at')
            ->select([
                'shifts.id', 'shifts.opened_at', 'shifts.closed_at', 'shifts.status',
                'shifts.opening_float', 'shifts.expected_cash', 'shifts.counted_cash', 'shifts.variance',
                'shifts.total_sales', 'shifts.cash_sales', 'shifts.card_sales', 'shifts.mobile_money_sales',
                'shifts.credit_sales', 'shifts.total_refunds', 'shifts.total_discounts', 'shifts.transaction_count',
                'shifts.notes',
                'users.name as cashier_name',
                'locations.name as location_name',
            ])
            ->paginate(25)
            ->withQueryString();

        $summary = Shift::where('business_id', $tenantId)
            ->whereBetween('opened_at', [$fromStart, $toEnd])
            ->when($effectiveLocationIds !== null, fn ($query) => $query->whereIn('location_id', $effectiveLocationIds))
            ->selectRaw("
                COUNT(*) as total_shifts,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                COALESCE(SUM(total_sales), 0) as total_sales,
                COALESCE(SUM(variance), 0) as total_variance,
                COALESCE(SUM(CASE WHEN variance < 0 THEN variance ELSE 0 END), 0) as total_shortage
            ")
            ->first();

        // ── Breakdown by location for the selected range ──────────────
        // Answers "how is each shop doing" at a glance, independent of the
        // location filter above (so switching the filter doesn't hide it).
        $byLocation = Shift::where('shifts.business_id', $tenantId)
            ->leftJoin('locations', 'shifts.location_id', '=', 'locations.id')
            ->whereBetween('shifts.opened_at', [$fromStart, $toEnd])
            ->when($scope !== null, fn ($query) => $query->whereIn('shifts.location_id', $scope))
            ->selectRaw("
                shifts.location_id,
                COALESCE(locations.name, 'Unassigned') as location_name,
                COUNT(*) as total_shifts,
                SUM(CASE WHEN shifts.status = 'open' THEN 1 ELSE 0 END) as open_count,
                COALESCE(SUM(shifts.total_sales), 0) as total_sales,
                COALESCE(SUM(shifts.variance), 0) as total_variance
            ")
            ->groupBy('shifts.location_id', 'locations.name')
            ->orderByDesc('total_sales')
            ->get();

        return Inertia::render('BackOffice/Shifts', [
            'open_shifts' => $openShifts,
            'shifts' => $shifts,
            'summary' => $summary,
            'by_location' => $byLocation,
            'locations' => Location::where('business_id', $tenantId)
                ->where('is_active', true)
                ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
                ->orderBy('name')
                ->get(['id', 'name']),
            'currency' => $currency,
            'filters' => ['from' => $from, 'to' => $to, 'status' => $status, 'location' => $locationId],
        ]);
    }
}
