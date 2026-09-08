<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FX·06 — "full history of every rate change kept for audit". Every
 * approved rate change is its own exchange_rates row, never updated in
 * place (see ApprovalService::applyApprovedAction()), so this is read-only
 * visibility over rows that already exist and already sync — no new data,
 * just the first place they're surfaced on the web. Owner/manager-only,
 * same permission as the financial statements: this is business-financial
 * data, not something every till user should see.
 */
class ExchangeRatesController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    /**
     * One row per non-base currency: its current rate plus who set it,
     * linking through to the full history per pair.
     */
    public function index(): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();
        $base = Currency::where('is_base', true)->first();

        $currencies = Currency::where('is_base', false)
            ->orderBy('name')
            ->get()
            ->map(function (Currency $currency) use ($tenantId, $base) {
                $current = ExchangeRate::where('business_id', $tenantId)
                    ->where('from_currency', $currency->code)
                    ->when($base, fn ($q) => $q->where('to_currency', $base->code))
                    ->whereNull('valid_until')
                    ->with('setBy:id,name')
                    ->latest('valid_from')
                    ->first();

                $changeCount = ExchangeRate::where('business_id', $tenantId)
                    ->where('from_currency', $currency->code)
                    ->when($base, fn ($q) => $q->where('to_currency', $base->code))
                    ->count();

                return [
                    'code' => $currency->code,
                    'name' => $currency->name,
                    'symbol' => $currency->symbol,
                    'current_rate' => $current?->rate !== null ? (float) $current->rate : null,
                    'set_by' => $current?->setBy?->name,
                    'valid_from' => $current?->valid_from?->toIso8601String(),
                    'change_count' => $changeCount,
                ];
            });

        return Inertia::render('BackOffice/ExchangeRates', [
            'base_currency' => $base?->code,
            'currencies' => $currencies,
        ]);
    }

    /**
     * Full chronological history for one currency pair — every row ever
     * written for it, requester/approver/reason resolved via the subject_id
     * join to approval_requests (see ExchangeRate::approvalRequest()).
     */
    public function show(string $fromCurrency): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();
        $base = Currency::where('is_base', true)->firstOrFail();
        $currency = Currency::where('code', $fromCurrency)->firstOrFail();

        $history = ExchangeRate::where('business_id', $tenantId)
            ->where('from_currency', $currency->code)
            ->where('to_currency', $base->code)
            ->with(['setBy:id,name', 'approvalRequest.requestedBy:id,name'])
            ->orderByDesc('valid_from')
            ->get()
            // "Current" means the most recent row AND still open — not just
            // "any open row". Before this fix's closing logic shipped, a
            // rate change never closed the row it replaced, so a pair can
            // have several old rows sitting with valid_until still null;
            // without the index===0 check every one of them would render
            // as "current" here (see the no-backfill note in
            // ApprovalService::applyApprovedAction()).
            ->values()
            ->map(fn (ExchangeRate $rate, int $index) => [
                'id' => $rate->id,
                'rate' => (float) $rate->rate,
                'source' => $rate->source,
                'locked' => $rate->locked,
                'valid_from' => $rate->valid_from?->toIso8601String(),
                'valid_until' => $rate->valid_until?->toIso8601String(),
                'is_current' => $index === 0 && $rate->valid_until === null,
                'approved_by' => $rate->setBy?->name,
                'requested_by' => $rate->approvalRequest?->requestedBy?->name,
                'reason' => $rate->approvalRequest?->reason,
            ]);

        return Inertia::render('BackOffice/ExchangeRateHistory', [
            'currency' => [
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
            ],
            'base_currency' => $base->code,
            'history' => $history,
        ]);
    }

    private function authorize(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::VIEW_FINANCIAL_STATEMENTS),
            403,
            'Access denied.'
        );
    }
}
