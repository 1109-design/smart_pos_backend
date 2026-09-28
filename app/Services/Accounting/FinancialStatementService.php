<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * Phase 11e — Trial Balance, Income Statement (P&L), and Balance Sheet, all
 * derived live from `general_ledger`. Nothing here is stored: every figure
 * is a SUM over posted rows, same principle as GlAccount::balance() and
 * PartyLedgerService — the reports can never drift from the ledger because
 * they ARE the ledger, re-shaped.
 *
 * There is no period-close journal that sweeps Revenue/COGS/Expenses into
 * Retained Earnings at year-end (Phase 11f, not built). Until that exists,
 * the Balance Sheet computes "Current Earnings" on the fly as the Income
 * Statement's net result since the business's accounting go-live date —
 * the standard way accounting software balances a Balance Sheet without a
 * formal close. It's a display-only figure: it posts to no account and
 * carries no gl_account_id, only a synthetic line in the Equity section.
 */
class FinancialStatementService
{
    /**
     * @return array{accounts: Collection<int, array<string, mixed>>, total_debit: float, total_credit: float, is_balanced: bool}
     */
    public function trialBalance(string $businessId, string $asOfDate): array
    {
        $rows = GeneralLedgerEntry::where('business_id', $businessId)
            ->whereRaw('DATE(trans_date) <= ?', [$asOfDate])
            ->selectRaw('gl_account_id, COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->groupBy('gl_account_id')
            ->get()
            ->keyBy('gl_account_id');

        $accounts = $this->accountsForBusiness($businessId)
            ->map(function ($account) use ($rows) {
                $isDebitNormal = (bool) $account->category?->is_debit_normal;
                $net = $this->netForAccount($account, $rows->get($account->id));

                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'category' => $account->category?->name,
                    'debit_balance' => $isDebitNormal ? max($net, 0.0) : max(-$net, 0.0),
                    'credit_balance' => $isDebitNormal ? max(-$net, 0.0) : max($net, 0.0),
                ];
            })
            ->filter(fn ($row) => abs($row['debit_balance']) > 0.005 || abs($row['credit_balance']) > 0.005)
            ->values();

        $totalDebit = round((float) $accounts->sum('debit_balance'), 4);
        $totalCredit = round((float) $accounts->sum('credit_balance'), 4);

        return [
            'accounts' => $accounts,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => abs($totalDebit - $totalCredit) <= 0.005,
        ];
    }

    /**
     * @return array{sections: Collection<int, array<string, mixed>>, total_revenue: float, total_cost_of_sales: float, total_expenses: float, net_income: float}
     */
    public function incomeStatement(string $businessId, string $fromDate, string $toDate): array
    {
        $activity = $this->activityByAccount($businessId, $fromDate, $toDate);

        $sections = $this->accountsForBusiness($businessId)
            ->filter(fn ($account) => $account->category?->statement_type === 'income_statement')
            ->groupBy(fn ($account) => $account->category->name)
            ->map(function (Collection $accounts, string $categoryName) use ($activity) {
                $category = $accounts->first()->category;

                $lines = $accounts->map(function ($account) use ($activity) {
                    $net = $this->netForAccount($account, $activity->get($account->id));

                    return ['code' => $account->code, 'name' => $account->name, 'amount' => $net];
                })->filter(fn ($line) => abs($line['amount']) > 0.005)->values();

                return [
                    'category' => $categoryName,
                    'reporting_order' => $category->reporting_order,
                    'lines' => $lines,
                    'total' => round((float) $lines->sum('amount'), 4),
                ];
            })
            ->sortBy('reporting_order')
            ->values();

        $totalRevenue = (float) ($sections->firstWhere('category', 'Revenue')['total'] ?? 0.0);
        $totalCostOfSales = (float) ($sections->firstWhere('category', 'Cost of Sales')['total'] ?? 0.0);
        $totalExpenses = (float) ($sections->firstWhere('category', 'Expenses')['total'] ?? 0.0);

        return [
            'sections' => $sections,
            'total_revenue' => round($totalRevenue, 4),
            'total_cost_of_sales' => round($totalCostOfSales, 4),
            'total_expenses' => round($totalExpenses, 4),
            'net_income' => round($totalRevenue - $totalCostOfSales - $totalExpenses, 4),
        ];
    }

    /**
     * @return array{sections: Collection<int, array<string, mixed>>, total_assets: float, total_liabilities: float, total_equity: float, is_balanced: bool}
     */
    public function balanceSheet(string $businessId, string $asOfDate): array
    {
        $business = Business::findOrFail($businessId);
        $sinceDate = $business->accounting_go_live_date?->toDateString() ?? '1970-01-01';

        $activity = $this->activityByAccount($businessId, $sinceDate, $asOfDate);

        $sections = $this->accountsForBusiness($businessId)
            ->filter(fn ($account) => $account->category?->statement_type === 'balance_sheet')
            ->groupBy(fn ($account) => $account->category->name)
            ->map(function (Collection $accounts, string $categoryName) use ($activity) {
                $category = $accounts->first()->category;

                $lines = $accounts->map(function ($account) use ($activity) {
                    $net = $this->netForAccount($account, $activity->get($account->id));

                    return ['code' => $account->code, 'name' => $account->name, 'amount' => $net];
                })->filter(fn ($line) => abs($line['amount']) > 0.005)->values();

                return [
                    'category' => $categoryName,
                    'reporting_order' => $category->reporting_order,
                    'lines' => $lines,
                    'total' => round((float) $lines->sum('amount'), 4),
                ];
            });

        $currentEarnings = round(
            $this->incomeStatement($businessId, $sinceDate, $asOfDate)['net_income'],
            4
        );

        $equitySection = $sections->get('Equity', [
            'category' => 'Equity', 'reporting_order' => 3000, 'lines' => collect(), 'total' => 0.0,
        ]);

        if (abs($currentEarnings) > 0.005) {
            $equitySection['lines'] = $equitySection['lines']->push([
                'code' => null,
                'name' => 'Current Earnings (unclosed)',
                'amount' => $currentEarnings,
            ])->values();
            $equitySection['total'] = round($equitySection['total'] + $currentEarnings, 4);
        }

        $sections = $sections->put('Equity', $equitySection)->sortBy('reporting_order')->values();

        $totalAssets = (float) ($sections->firstWhere('category', 'Assets')['total'] ?? 0.0);
        $totalLiabilities = (float) ($sections->firstWhere('category', 'Liabilities')['total'] ?? 0.0);
        $totalEquity = (float) ($sections->firstWhere('category', 'Equity')['total'] ?? 0.0);

        return [
            'sections' => $sections,
            'total_assets' => round($totalAssets, 4),
            'total_liabilities' => round($totalLiabilities, 4),
            'total_equity' => round($totalEquity, 4),
            'is_balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) <= 0.005,
        ];
    }

    /**
     * @return Collection<int, GlAccount>
     */
    private function accountsForBusiness(string $businessId): Collection
    {
        return AccountCategory::where('business_id', $businessId)
            ->with(['accounts' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->flatMap(fn ($category) => $category->accounts->each(fn ($account) => $account->setRelation('category', $category)));
    }

    /**
     * Debit/credit totals per account for one date range — the shared
     * building block behind both the Income Statement (a period range) and
     * the Balance Sheet's rolled-up "Current Earnings" (since go-live).
     *
     * @return Collection<string, object{total_debit: float, total_credit: float}>
     */
    private function activityByAccount(string $businessId, string $fromDate, string $toDate): Collection
    {
        return GeneralLedgerEntry::where('business_id', $businessId)
            ->whereRaw('DATE(trans_date) >= ?', [$fromDate])
            ->whereRaw('DATE(trans_date) <= ?', [$toDate])
            ->selectRaw('gl_account_id, COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->groupBy('gl_account_id')
            ->get()
            ->keyBy('gl_account_id');
    }

    private function netForAccount(GlAccount $account, ?object $row): float
    {
        if (! $row) {
            return 0.0;
        }

        $debit = (float) $row->total_debit;
        $credit = (float) $row->total_credit;

        return round($account->category?->is_debit_normal ? $debit - $credit : $credit - $debit, 4);
    }
}
