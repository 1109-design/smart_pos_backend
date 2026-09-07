<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Asset;
use App\Models\Business;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Phase 9 / Phase 11d — asset acquisition, diminishing-balance depreciation,
 * and disposal, all posted through the same JournalService every other module
 * uses. Tolerant of failure like SalePostingService: a posting problem here
 * (a closed period, a missing account) is logged and skipped rather than
 * thrown, since it must never block the BackOffice action that triggered
 * it (creating, sweeping, or disposing of an asset).
 *
 * Deliberately cash-purchase only — acquisition debits Fixed Assets and
 * credits Cash or Bank directly. A supplier-financed asset purchase (on
 * credit, through Accounts Payable) isn't modeled; nothing has asked for
 * it yet and bolting it on speculatively would just be a guess at the
 * shape a real request would actually need.
 */
class AssetPostingService
{
    private const FIXED_ASSETS = '1500';

    private const ACCUMULATED_DEPRECIATION = '1510';

    private const DEPRECIATION_EXPENSE = '6070';

    private const DISPOSAL_VARIANCE = ['code' => '6075', 'name' => 'Gain/Loss on Disposal of Assets'];

    public function __construct(
        private readonly JournalService $journals,
        private readonly ChartOfAccountsSeeder $chartSeeder,
    ) {}

    public function recordAcquisition(Asset $asset): void
    {
        if (! $this->isLive($asset->business_id)) {
            return;
        }

        try {
            $fundingCode = $asset->funding_method === 'bank' ? '1010' : '1000';

            $header = $this->journals->createDraft(
                $asset->business_id,
                $asset->acquisition_date->toDateString(),
                'asset_acquisition',
                $asset->id,
                "Acquired {$asset->name}",
            );

            $this->journals->addLine($header, [
                'gl_account_id' => $this->account($asset->business_id, self::FIXED_ASSETS)->id,
                'debit' => (float) $asset->acquisition_cost,
                'party_type' => 'asset',
                'party_id' => $asset->id,
            ]);
            $this->journals->addLine($header, [
                'gl_account_id' => $this->account($asset->business_id, $fundingCode)->id,
                'credit' => (float) $asset->acquisition_cost,
            ]);

            $this->journals->post($header);
        } catch (RuntimeException $e) {
            Log::warning("AssetPostingService::recordAcquisition failed for asset {$asset->id}: {$e->getMessage()}");
        }
    }

    /**
     * Catches up every whole month of depreciation elapsed since the last
     * posted period, for every active asset in every accounting-live
     * business — same "sweep, callable on a schedule or on demand" shape as
     * PostPendingSales. One journal per missing month, dated at that
     * month's end, so a period-close later still sees the charge landing in
     * the month it actually belongs to. Idempotent: counts existing
     * depreciation-tagged journals for the asset and only posts the
     * shortfall.
     *
     * @return int number of depreciation journals posted, across all businesses
     */
    public function postMonthlyDepreciation(?string $businessId = null, ?string $asOfDate = null): int
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate) : now();
        $posted = 0;

        $businesses = $businessId
            ? Business::where('id', $businessId)->get()
            : Business::whereNotNull('accounting_go_live_date')->get();

        foreach ($businesses as $business) {
            if (! $this->isLive($business->id)) {
                continue;
            }

            $assets = Asset::where('business_id', $business->id)->where('status', 'active')->get();

            foreach ($assets as $asset) {
                $posted += $this->catchUpAsset($asset, $asOf);
            }
        }

        return $posted;
    }

    public function recordDisposal(Asset $asset, string $disposalDate, float $proceeds): void
    {
        if (! $this->isLive($asset->business_id)) {
            return;
        }

        try {
            $accumulated = $asset->accumulatedDepreciation($asset->business_id);
            $originalCost = (float) $asset->acquisition_cost;
            $netBookValue = round($originalCost - $accumulated, 4);
            // Gain (proceeds exceed book value) credits the variance account
            // (reduces net expense); a loss debits it — same "one variance
            // account absorbs both directions" pattern as Purchase Price
            // Variance and Cash Vault Variance.
            $gainOrLoss = round($proceeds - $netBookValue, 4);

            $header = $this->journals->createDraft(
                $asset->business_id,
                $disposalDate,
                'asset_disposal',
                $asset->id,
                "Disposed of {$asset->name}",
            );

            if ($accumulated > 0.005) {
                $this->journals->addLine($header, [
                    'gl_account_id' => $this->account($asset->business_id, self::ACCUMULATED_DEPRECIATION)->id,
                    'debit' => $accumulated,
                    'party_type' => 'asset',
                    'party_id' => $asset->id,
                ]);
            }

            if ($proceeds > 0.005) {
                $fundingCode = $asset->funding_method === 'bank' ? '1010' : '1000';
                $this->journals->addLine($header, [
                    'gl_account_id' => $this->account($asset->business_id, $fundingCode)->id,
                    'debit' => $proceeds,
                ]);
            }

            $this->journals->addLine($header, [
                'gl_account_id' => $this->account($asset->business_id, self::FIXED_ASSETS)->id,
                'credit' => $originalCost,
                'party_type' => 'asset',
                'party_id' => $asset->id,
            ]);

            if (abs($gainOrLoss) > 0.005) {
                $variance = $this->chartSeeder->ensureAccount(
                    $asset->business_id,
                    'Expenses',
                    'Other Expenses',
                    self::DISPOSAL_VARIANCE,
                );

                // A loss (gainOrLoss negative) debits the expense account;
                // a gain (positive) credits it.
                $this->journals->addLine($header, [
                    'gl_account_id' => $variance->id,
                    'debit' => $gainOrLoss < 0 ? abs($gainOrLoss) : 0,
                    'credit' => $gainOrLoss > 0 ? $gainOrLoss : 0,
                ]);
            }

            $this->journals->post($header);
        } catch (RuntimeException $e) {
            Log::warning("AssetPostingService::recordDisposal failed for asset {$asset->id}: {$e->getMessage()}");
        }
    }

    private function catchUpAsset(Asset $asset, Carbon $asOf): int
    {
        $cappedAsOf = $asOf->lt(now()) ? $asOf : now();

        // diffInMonths() returns an absolute value regardless of direction —
        // a future-dated acquisition (a data-entry mistake, or a business
        // backfilling a not-yet-received asset) must count as zero elapsed
        // periods, not the magnitude of the gap. It also returns a float
        // (a fractional month, e.g. 5.02), which must be floored to a whole
        // month count before it's used as a loop bound — left as a float,
        // the loop below runs one extra iteration for any elapsed time past
        // the exact month boundary.
        $monthsElapsed = $asset->acquisition_date->gt($cappedAsOf)
            ? 0
            : min($asset->useful_life_months, (int) floor($asset->acquisition_date->diffInMonths($cappedAsOf)));

        $alreadyPosted = JournalHeader::where('source_type', 'depreciation')
            ->where('source_id', $asset->id)
            ->count();

        $missing = $monthsElapsed - $alreadyPosted;
        if ($missing <= 0) {
            return 0;
        }

        $posted = 0;

        for ($i = 0; $i < $missing; $i++) {
            $periodNumber = $alreadyPosted + $i + 1;
            $periodEnd = $asset->acquisition_date->copy()->addMonthsNoOverflow($periodNumber)->endOfMonth();
            $transDate = $periodEnd->gt($cappedAsOf) ? $cappedAsOf->toDateString() : $periodEnd->toDateString();

            // Pure diminishing balance never quite reaches salvage value in
            // a finite number of periods (each charge is a fraction of
            // whatever remains). Writing off the entire remaining
            // depreciable amount in the asset's LAST useful-life period —
            // rather than another rate-based fraction of it — guarantees
            // the asset is fully depreciated by the end of its useful life,
            // same guarantee straight-line gave for free.
            $isFinalPeriod = $periodNumber >= $asset->useful_life_months;
            $amount = $isFinalPeriod
                ? round(max(0, $asset->bookValue($asset->business_id) - (float) $asset->salvage_value), 4)
                : $asset->monthlyDepreciation($asset->business_id);

            try {
                $header = $this->journals->createDraft(
                    $asset->business_id,
                    $transDate,
                    'depreciation',
                    $asset->id,
                    "Depreciation — {$asset->name}",
                );

                $this->journals->addLine($header, [
                    'gl_account_id' => $this->account($asset->business_id, self::DEPRECIATION_EXPENSE)->id,
                    'debit' => $amount,
                ]);
                $this->journals->addLine($header, [
                    'gl_account_id' => $this->account($asset->business_id, self::ACCUMULATED_DEPRECIATION)->id,
                    'credit' => $amount,
                    'party_type' => 'asset',
                    'party_id' => $asset->id,
                ]);

                $this->journals->post($header);
                $posted++;
            } catch (RuntimeException $e) {
                Log::warning("AssetPostingService::postMonthlyDepreciation failed for asset {$asset->id}: {$e->getMessage()}");
                break;
            }
        }

        return $posted;
    }

    private function account(string $businessId, string $code): GlAccount
    {
        $account = GlAccount::where('business_id', $businessId)->where('code', $code)->first();

        throw_unless($account, new RuntimeException("GL account {$code} not found for business {$businessId}."));

        return $account;
    }

    private function isLive(string $businessId): bool
    {
        return (bool) Business::find($businessId)?->accountingIsLive();
    }
}
