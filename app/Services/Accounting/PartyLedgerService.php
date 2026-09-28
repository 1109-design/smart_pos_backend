<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use Illuminate\Support\Carbon;

/**
 * Phase 11c — a customer's or supplier's balance and statement are never
 * stored, only derived from whatever's posted against them in the general
 * ledger (party_type/party_id on each line) — so a debtor/creditor
 * sub-ledger can never drift from the Accounts Receivable/Payable control
 * account, because they're the same rows, not a copy. One service serves
 * both parties — but the math is NOT simply "debit minus credit" for both:
 * a customer's balance lives on Accounts Receivable (debit-normal, so
 * "they owe us more" is a debit), while a supplier's lives on Accounts
 * Payable (credit-normal, so "we owe them more" is a credit). Every method
 * here normalizes through sign() so "positive = owed" reads the same way
 * for either party type, rather than assuming every party is debit-normal.
 * See the Phase 11 General Ledger Blueprint §6.
 */
class PartyLedgerService
{
    /**
     * +1 for a debit-normal party (customer/Accounts Receivable): a debit
     * increases what they owe us. -1 for a credit-normal party
     * (supplier/Accounts Payable): a credit increases what we owe them.
     * Without this, every balance/aging figure for a supplier comes out
     * exactly sign-inverted — caught only once Part B of the Purchasing &
     * Cash Vault Blueprint started posting real supplier data; until then
     * this whole class had only ever been exercised against customers.
     */
    private function sign(string $partyType): float
    {
        return $partyType === 'supplier' ? -1.0 : 1.0;
    }

    /**
     * A chronological transaction list with a running balance, plus the
     * opening balance carried in from before $fromDate (0 if omitted).
     *
     * @return array{opening_balance: float, closing_balance: float, lines: array<int, array{date: string, description: ?string, debit: float, credit: float, running_balance: float}>}
     */
    public function statement(
        string $businessId,
        string $partyType,
        string $partyId,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $toDate ??= now()->toDateString();
        $sign = $this->sign($partyType);
        // Every date filter here compares via SQL DATE(trans_date), not a
        // plain column comparison against a "Y-m-d" string — trans_date is
        // stored with a "00:00:00" time part, and a same-day boundary (e.g.
        // trans_date = toDate) compares as string > date-only lexically,
        // wrongly excluding it. MySQL's native DATE type masks this; SQLite
        // doesn't, which is how this was caught.

        $openingBalance = 0.0;
        if ($fromDate) {
            $openingBalance = $sign * (float) GeneralLedgerEntry::where('business_id', $businessId)
                ->where('party_type', $partyType)
                ->where('party_id', $partyId)
                ->whereRaw('DATE(trans_date) < ?', [$fromDate])
                ->selectRaw('COALESCE(SUM(debit - credit), 0) as bal')
                ->value('bal');
        }

        $query = GeneralLedgerEntry::with('header')
            ->where('business_id', $businessId)
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->whereRaw('DATE(trans_date) <= ?', [$toDate]);

        if ($fromDate) {
            $query->whereRaw('DATE(trans_date) >= ?', [$fromDate]);
        }

        $running = $openingBalance;
        $rows = [];

        foreach ($query->orderBy('trans_date')->orderBy('created_at')->get() as $line) {
            $running += $sign * ((float) $line->debit - (float) $line->credit);
            $rows[] = [
                'date' => $line->trans_date->toDateString(),
                'description' => $line->description ?? $line->header?->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'running_balance' => round($running, 4),
            ];
        }

        return [
            'opening_balance' => round($openingBalance, 4),
            'closing_balance' => round($running, 4),
            'lines' => $rows,
        ];
    }

    /**
     * FIFO age analysis: a credit (payment/credit-note) is applied against
     * the oldest outstanding debit (charge) first, and whatever debit
     * amount remains unmatched is bucketed by its own original date, not
     * the as-of date. A net credit balance (overpaid or prepaid) is
     * reported separately rather than forced into a negative bucket.
     *
     * @return array{buckets: array{current: float, days_31_60: float, days_61_90: float, days_91_120: float, over_120: float}, total_outstanding: float, credit_balance: float}
     */
    public function agingBuckets(
        string $businessId,
        string $partyType,
        string $partyId,
        ?string $asOfDate = null,
    ): array {
        $asOfDate = $asOfDate ?? now()->toDateString();
        $asOf = Carbon::parse($asOfDate);
        $sign = $this->sign($partyType);

        // See statement()'s comment on why this is DATE(trans_date), not a
        // plain column comparison, against the as-of date.
        $lines = GeneralLedgerEntry::where('business_id', $businessId)
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->whereRaw('DATE(trans_date) <= ?', [$asOfDate])
            ->orderBy('trans_date')
            ->orderBy('created_at')
            ->get();

        $charges = [];
        $creditBalance = 0.0;

        foreach ($lines as $line) {
            // "$net > 0" must mean "this line increased what's owed" for
            // either party type — for a supplier that's a credit, not a
            // debit, hence the sign flip here rather than a raw subtraction.
            $net = $sign * ((float) $line->debit - (float) $line->credit);

            if ($net > 0.005) {
                $charges[] = ['date' => $line->trans_date, 'amount' => $net];

                continue;
            }

            if ($net < -0.005) {
                $toApply = -$net;
                foreach ($charges as &$charge) {
                    if ($toApply <= 0.005) {
                        break;
                    }
                    $reduce = min($charge['amount'], $toApply);
                    $charge['amount'] -= $reduce;
                    $toApply -= $reduce;
                }
                unset($charge);

                if ($toApply > 0.005) {
                    $creditBalance += $toApply;
                }
            }
        }

        $buckets = [
            'current' => 0.0, 'days_31_60' => 0.0, 'days_61_90' => 0.0, 'days_91_120' => 0.0, 'over_120' => 0.0,
        ];

        foreach ($charges as $charge) {
            if ($charge['amount'] <= 0.005) {
                continue;
            }

            $ageDays = $charge['date']->diffInDays($asOf);
            $bucket = match (true) {
                $ageDays <= 30 => 'current',
                $ageDays <= 60 => 'days_31_60',
                $ageDays <= 90 => 'days_61_90',
                $ageDays <= 120 => 'days_91_120',
                default => 'over_120',
            };
            $buckets[$bucket] += $charge['amount'];
        }

        foreach ($buckets as $key => $value) {
            $buckets[$key] = round($value, 4);
        }

        return [
            'buckets' => $buckets,
            'total_outstanding' => round(array_sum($buckets), 4),
            'credit_balance' => round($creditBalance, 4),
        ];
    }

    public function currentBalance(string $businessId, string $partyType, string $partyId): float
    {
        return $this->sign($partyType) * (float) GeneralLedgerEntry::where('business_id', $businessId)
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as bal')
            ->value('bal');
    }
}
