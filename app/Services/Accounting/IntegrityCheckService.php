<?php

namespace App\Services\Accounting;

use App\Models\Accounting\IntegrityCheckRun;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 12.1 — the server's read-only check of a business's books, run
 * nightly: the trial balance is zero, every journal balances, receivables
 * and payables control accounts agree with open invoices, Take-on
 * Clearing is empty once take-on is finalised. It never changes anything;
 * a difference is stored and logged for someone to look at.
 */
class IntegrityCheckService
{
    private const TOLERANCE = 0.01;

    /**
     * The ledger month by month — what devices compare their own copy to.
     *
     * @return array<string, array{lines: int, debit: float, credit: float}>
     */
    public function ledgerSummary(string $businessId): array
    {
        return DB::table('general_ledger')
            ->where('business_id', $businessId)
            ->selectRaw('SUBSTR(trans_date, 1, 7) AS m, COUNT(*) AS n, SUM(debit) AS d, SUM(credit) AS c')
            ->groupBy('m')
            ->orderBy('m')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->m => [
                'lines' => (int) $r->n,
                'debit' => round((float) $r->d, 2),
                'credit' => round((float) $r->c, 2),
            ]])
            ->all();
    }

    /**
     * @return list<array{check: string, ok: bool, detail: string, difference: float}>
     */
    public function findings(string $businessId): array
    {
        $out = [];

        $tb = DB::table('general_ledger')->where('business_id', $businessId)
            ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();
        $diff = round((float) $tb->d - (float) $tb->c, 2);
        $out[] = $this->finding('trial_balance', abs($diff) < self::TOLERANCE, $diff,
            abs($diff) < self::TOLERANCE ? 'The trial balance is zero.' : "Debits exceed credits by {$diff}.");

        $unbalanced = DB::table('journal_headers as h')
            ->join('journal_lines as l', 'l.journal_header_id', '=', 'h.id')
            ->where('h.business_id', $businessId)
            ->whereIn('h.status', ['posted', 'reversed'])
            ->groupBy('h.id', 'h.journal_number')
            ->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) >= ?', [self::TOLERANCE])
            ->pluck('h.journal_number');
        $out[] = $this->finding('journals_balance', $unbalanced->isEmpty(), (float) $unbalanced->count(),
            $unbalanced->isEmpty() ? 'Every posted journal balances.' : $unbalanced->count().' journal(s) don\'t balance: '.$unbalanced->take(5)->implode(', '));

        $out[] = $this->controlVsOpen($businessId, 'receivables', 'receivable',
            (float) DB::table('invoices')->where('business_id', $businessId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->selectRaw('COALESCE(SUM(total - amount_paid), 0) AS o')->value('o'),
            debitNormal: true);
        $out[] = $this->controlVsOpen($businessId, 'payables', 'payable',
            (float) DB::table('supplier_invoices')->where('business_id', $businessId)
                ->whereIn('status', ['posted', 'partially_paid'])
                ->selectRaw('COALESCE(SUM(amount), 0) AS o')->value('o')
            - (float) DB::table('supplier_payment_allocations as a')
                ->join('supplier_invoices as i', 'i.id', '=', 'a.supplier_invoice_id')
                ->where('i.business_id', $businessId)->whereIn('i.status', ['posted', 'partially_paid'])
                ->sum('a.amount'),
            debitNormal: false);

        $out[] = $this->inventoryVsStock($businessId);
        $out[] = $this->cashVsPayments($businessId);

        $business = Business::find($businessId);
        $clearing = DB::table('gl_accounts')->where('business_id', $businessId)->where('code', '3025')->value('id');
        if ($business?->take_on_finalised_at && $clearing) {
            $b = round((float) DB::table('general_ledger')->where('gl_account_id', $clearing)->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')->value('b'), 2);
            $out[] = $this->finding('take_on_clearing', abs($b) < self::TOLERANCE, $b,
                abs($b) < self::TOLERANCE ? 'Take-on Clearing is empty.' : "Take-on Clearing holds {$b}.");
        }

        return $out;
    }

    public function run(string $businessId): IntegrityCheckRun
    {
        $findings = $this->findings($businessId);
        $ok = collect($findings)->every(fn ($f) => $f['ok']);
        if (! $ok) {
            Log::warning('Accounting integrity check found differences', [
                'business_id' => $businessId,
                'failed' => collect($findings)->where('ok', false)->values()->all(),
            ]);
        }

        return IntegrityCheckRun::create([
            'business_id' => $businessId,
            'ok' => $ok,
            'findings' => $findings,
            'ran_at' => now(),
        ]);
    }

    /** A role's account: the business's mapping, else the default code. */
    private function roleAccount(string $businessId, string $role, string $code): ?string
    {
        return DB::table('account_role_mappings')->where('business_id', $businessId)->where('role', $role)->value('gl_account_id')
            ?? DB::table('gl_accounts')->where('business_id', $businessId)->where('code', $code)->value('id');
    }

    /**
     * Inventory accounts against stock on hand at cost — the same
     * comparison the device makes (StockReportService.stockVsLedger).
     *
     * @return array{check: string, ok: bool, detail: string, difference: float}
     */
    private function inventoryVsStock(string $businessId): array
    {
        $accounts = DB::table('gl_accounts')->where('business_id', $businessId)->where('control_type', 'inventory')->pluck('id');
        if ($role = $this->roleAccount($businessId, 'inventory', '1200')) {
            $accounts->push($role);
        }
        $ledger = (float) DB::table('general_ledger')->whereIn('gl_account_id', $accounts->unique())
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')->value('b');
        $stock = (float) DB::table('products')->where('business_id', $businessId)->where('track_stock', true)
            ->selectRaw('COALESCE(SUM(stock_quantity * cost_price), 0) AS v')->value('v');
        $variance = round($stock - $ledger, 2);

        return $this->finding('inventory', abs($variance) < self::TOLERANCE, $variance,
            abs($variance) < self::TOLERANCE ? 'Stock on hand at cost agrees with the inventory accounts.' : "Stock on hand at cost differs from the inventory accounts by {$variance}.");
    }

    /**
     * What sales put into cash, bank and mobile money accounts against the
     * payments behind them — the same comparison the device makes
     * (CashPaymentsReconciliationService).
     *
     * @return array{check: string, ok: bool, detail: string, difference: float}
     */
    private function cashVsPayments(string $businessId): array
    {
        $cash = $this->roleAccount($businessId, 'default_cash', '1000');
        $bank = $this->roleAccount($businessId, 'default_bank', '1010');
        $mobile = $this->roleAccount($businessId, 'default_mobile_money', '1020');
        $named = DB::table('bank_accounts')->where('business_id', $businessId)->pluck('gl_account_id', 'id');
        $accounts = collect([$cash, $bank, $mobile])->merge($named->values())
            ->merge(DB::table('payment_method_configs')->where('business_id', $businessId)->whereNotNull('gl_account_id')->pluck('gl_account_id'))
            ->filter()->unique()->values();
        if ($accounts->isEmpty()) {
            return $this->finding('cash', true, 0, 'No cash or bank accounts to check.');
        }

        $voided = DB::table('transactions')->where('business_id', $businessId)->where('status', 'voided')->pluck('id');
        $journals = DB::table('journal_headers')->where('business_id', $businessId)
            ->where('source_type', 'sale')->where('status', 'posted')
            ->whereNotIn('source_id', $voided)->pluck('id');
        $ledger = (float) DB::table('general_ledger')->whereIn('journal_header_id', $journals)
            ->whereIn('gl_account_id', $accounts)->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')->value('b');

        $payments = DB::table('payments as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('t.business_id', $businessId)->where('t.status', '!=', 'voided')
            ->get(['p.method', 'p.base_equivalent', 'p.gl_account_id', 'p.bank_account_id', 't.total']);
        $subledger = 0.0;
        foreach ($payments as $p) {
            $method = strtolower(trim((string) $p->method));
            if (in_array($method, ['exchange_credit', 'legacy_deposit'], true) || $method === 'credit' || str_starts_with($method, 'credit ')) {
                continue;
            }
            $isBank = in_array($method, ['bank_transfer', 'bank transfer', 'bank', 'transfer'], true) || str_starts_with($method, 'bank transfer');
            $isCard = ! $isBank && ! str_starts_with($method, 'cash') && (str_contains($method, 'card') || str_contains($method, 'swipe'));
            $isMobile = ! $isBank && ! $isCard && ! str_starts_with($method, 'cash') && (str_contains($method, 'ecocash') || str_contains($method, 'mobile'));
            $target = $p->gl_account_id && $accounts->contains($p->gl_account_id) ? $p->gl_account_id : null;
            if ($target === null) {
                $target = match (true) {
                    $isBank || $isCard => ($p->bank_account_id && $accounts->contains($named[$p->bank_account_id] ?? null)) ? $named[$p->bank_account_id] : $bank,
                    $isMobile => $mobile,
                    default => $cash,
                };
            }
            if ($target === null || ! $accounts->contains($target)) {
                continue;
            }
            $subledger += ((float) $p->total < 0 ? -1 : 1) * (float) $p->base_equivalent;
        }
        $variance = round($ledger - $subledger, 2);

        return $this->finding('cash', abs($variance) < self::TOLERANCE, $variance,
            abs($variance) < self::TOLERANCE ? 'Sales takings agree with the cash and bank accounts.' : "Sales takings differ from the cash and bank accounts by {$variance}.");
    }

    /**
     * @return array{check: string, ok: bool, detail: string, difference: float}
     */
    private function controlVsOpen(string $businessId, string $check, string $controlType, float $open, bool $debitNormal): array
    {
        $accounts = DB::table('gl_accounts')->where('business_id', $businessId)->where('control_type', $controlType)->pluck('id');
        $balance = (float) DB::table('general_ledger')->whereIn('gl_account_id', $accounts)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS b')->value('b');
        $control = $debitNormal ? $balance : -$balance;
        $variance = round($control - $open, 2);
        $what = $check === 'receivables' ? 'Open customer invoices' : 'Open supplier invoices';

        return $this->finding($check, abs($variance) < self::TOLERANCE, $variance,
            abs($variance) < self::TOLERANCE ? "{$what} agree with the control account." : "{$what} differ from the control account by {$variance}.");
    }

    /**
     * @return array{check: string, ok: bool, detail: string, difference: float}
     */
    private function finding(string $check, bool $ok, float $difference, string $detail): array
    {
        return ['check' => $check, 'ok' => $ok, 'detail' => $detail, 'difference' => $difference];
    }
}
