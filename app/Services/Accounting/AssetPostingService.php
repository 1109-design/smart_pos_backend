<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Business;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
    // Still hardcoded, deliberately: the monthly depreciation sweep below
    // (postMonthlyDepreciation()/catchUpAsset()) stays server-side-only and
    // unconfigurable this phase — see this class's own note on
    // AccountRoleMappingService's 'accumulated_depreciation' omission.
    private const ACCUMULATED_DEPRECIATION = '1510';

    private const DEPRECIATION_EXPENSE = '6070';

    // How long a period sits eligible-but-unposted before this sweep treats
    // it as a straggler and posts it anyway — see catchUpAsset()'s own doc
    // comment on why this can't just be a $viaSweep parameter the way every
    // other posting service's sweep command uses.
    private const GRACE_PERIOD_HOURS = 24;

    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    /**
     * Entry point for a Flutter-originated asset row landing via sync —
     * decides acquisition vs. disposal from the row's own `status`, mirroring
     * SalePostingService::postIfReady()'s status-branching. Idempotent and
     * cutover-gated like every other sync-triggered posting service; a
     * direct BackOffice action keeps calling recordAcquisition()/
     * recordDisposal() below directly instead (a person is waiting there).
     *
     * @param  bool  $viaSweep  True only from the pending-asset-transactions
     *                          sweep's grace-period fallback — see
     *                          SalePostingService::postIfReady()'s identical
     *                          parameter.
     */
    public function postIfReady(Asset $asset, bool $viaSweep = false): void
    {
        if (! $this->isLive($asset->business_id)) {
            return;
        }

        $business = Business::find($asset->business_id);
        $transDate = $asset->acquisition_date->toDateString();
        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return;
        }

        if (! JournalHeader::where('source_type', 'asset_acquisition')->where('source_id', $asset->id)->exists()) {
            $this->recordAcquisition($asset);
        }

        if ($asset->status === 'disposed'
            && $asset->disposed_at
            && ! JournalHeader::where('source_type', 'asset_disposal')->where('source_id', $asset->id)->exists()) {
            $this->recordDisposal($asset, $asset->disposed_at->toDateString(), (float) ($asset->disposal_proceeds ?? 0));
        }
    }

    public function recordAcquisition(Asset $asset): void
    {
        if (! $this->isLive($asset->business_id)) {
            return;
        }

        try {
            $fixedAssets = $this->mappings->resolve($asset->business_id, 'fixed_assets');
            $funding = $this->resolveFundingAccount($asset->business_id, $asset->funding_method, $asset->bank_account_id);

            $header = $this->journals->createDraft(
                $asset->business_id,
                $asset->acquisition_date->toDateString(),
                'asset_acquisition',
                $asset->id,
                "Acquired {$asset->name}",
            );

            $this->journals->addLine($header, [
                'gl_account_id' => $fixedAssets->id,
                'debit' => (float) $asset->acquisition_cost,
                'party_type' => 'asset',
                'party_id' => $asset->id,
            ]);
            $this->journals->addLine($header, [
                'gl_account_id' => $funding->id,
                'credit' => (float) $asset->acquisition_cost,
            ]);

            $this->journals->post($header);
        } catch (Throwable $e) {
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
                $funding = $this->resolveFundingAccount($asset->business_id, $asset->funding_method, $asset->disposal_bank_account_id);
                $this->journals->addLine($header, [
                    'gl_account_id' => $funding->id,
                    'debit' => $proceeds,
                ]);
            }

            $this->journals->addLine($header, [
                'gl_account_id' => $this->mappings->resolve($asset->business_id, 'fixed_assets')->id,
                'credit' => $originalCost,
                'party_type' => 'asset',
                'party_id' => $asset->id,
            ]);

            if (abs($gainOrLoss) > 0.005) {
                $variance = $this->mappings->resolve($asset->business_id, 'disposal_gain_loss');

                // A loss (gainOrLoss negative) debits the expense account;
                // a gain (positive) credits it.
                $this->journals->addLine($header, [
                    'gl_account_id' => $variance->id,
                    'debit' => $gainOrLoss < 0 ? abs($gainOrLoss) : 0,
                    'credit' => $gainOrLoss > 0 ? $gainOrLoss : 0,
                ]);
            }

            $this->journals->post($header);
        } catch (Throwable $e) {
            Log::warning("AssetPostingService::recordDisposal failed for asset {$asset->id}: {$e->getMessage()}");
        }
    }

    private function resolveFundingAccount(string $businessId, string $fundingMethod, ?string $bankAccountId): GlAccount
    {
        $role = $fundingMethod === 'bank' ? 'default_bank' : 'default_cash';
        $account = $this->mappings->resolve($businessId, $role);

        return $role === 'default_bank' ? $this->resolveBankAccount($bankAccountId, $account) : $account;
    }

    /**
     * See SalePostingService::resolveBankAccount() — identical fallback
     * behavior.
     */
    private function resolveBankAccount(?string $bankAccountId, GlAccount $default): GlAccount
    {
        if (! $bankAccountId) {
            return $default;
        }

        $bankAccount = BankAccount::find($bankAccountId);
        $glAccount = $bankAccount ? GlAccount::find($bankAccount->gl_account_id) : null;

        return $glAccount ?? $default;
    }

    /**
     * Unlike every other posting service's sweep, this can't take a single
     * $viaSweep flag from its caller — postMonthlyDepreciation() is called
     * once per business, but eligibility must be decided per PERIOD, since
     * diminishing-balance depreciation is sequential (period N's amount
     * depends on every prior period's effect on book value, so periods
     * can't be posted out of order or with gaps). So the check lives here,
     * computed per period as the loop reaches it: once a period becomes
     * eligible for client-side posting (Flutter's DepreciationPostingService
     * — see its own doc comment on the symmetric client-side half of this),
     * this sweep stops rather than racing it — UNLESS that period has sat
     * eligible-but-unposted for longer than the grace period, in which case
     * it's treated as a straggler (an offline device that never got a
     * chance to post it) and this sweep claims it after all, exactly like
     * every other posting service's grace-period fallback.
     */
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

        $business = Business::find($asset->business_id);
        $posted = 0;

        for ($i = 0; $i < $missing; $i++) {
            $periodNumber = $alreadyPosted + $i + 1;
            $periodEnd = $asset->acquisition_date->copy()->addMonthsNoOverflow($periodNumber)->endOfMonth();
            $transDate = $periodEnd->gt($cappedAsOf) ? $cappedAsOf->toDateString() : $periodEnd->toDateString();

            $clientEligible = $business && $business->postsFromClientFor($transDate);
            $pastGracePeriod = Carbon::parse($transDate)->lte(now()->subHours(self::GRACE_PERIOD_HOURS));
            if ($clientEligible && ! $pastGracePeriod) {
                // This period — and, since eligibility only moves forward
                // in time, every remaining period in this run — is Flutter's
                // to post, and hasn't even been outstanding long enough to
                // call it a straggler yet. Stop here rather than race it.
                break;
            }

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
