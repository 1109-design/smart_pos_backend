<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\SalaryPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a payroll disbursement to the general ledger: Dr 6020 Wages, Cr
 * whichever cash/bank/mobile-money account the payment method implies.
 * Cheque payments post to Bank (1010) — a cheque is a bank instrument, not
 * a separate clearing account this chart tracks.
 *
 * Valued at base_equivalent (already converted at the rate recorded when
 * the payment was made on the till), same as every other multi-currency
 * posting service in this app — see SalePostingService.
 */
class SalaryPostingService
{
    private const FUNDING_ACCOUNT_CODES = [
        'cash' => '1000',
        'bank_transfer' => '1010',
        'cheque' => '1010',
        'mobile_money' => '1020',
    ];

    public function __construct(private readonly JournalService $journals) {}

    public function recordPayment(SalaryPayment $payment): void
    {
        if (JournalHeader::where('source_type', 'salary_payment')->where('source_id', $payment->id)->exists()) {
            return; // already processed — sync can redeliver the same record
        }

        $business = Business::find($payment->business_id);
        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = $payment->paid_at?->toDateString() ?? now()->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        $amount = round((float) $payment->base_equivalent, 4);
        if ($amount <= 0.005) {
            return;
        }

        try {
            DB::transaction(function () use ($payment, $transDate, $amount) {
                $wages = GlAccount::where('business_id', $payment->business_id)->where('code', '6020')->first();
                $fundingCode = self::FUNDING_ACCOUNT_CODES[$payment->payment_method] ?? '1000';
                $funding = GlAccount::where('business_id', $payment->business_id)->where('code', $fundingCode)->first();

                if (! $wages || ! $funding) {
                    Log::warning("Accounting: chart of accounts missing Wages/funding account for business {$payment->business_id} — skipping salary payment {$payment->id}.");

                    return;
                }

                $header = $this->journals->createDraft(
                    $payment->business_id,
                    $transDate,
                    'salary_payment',
                    $payment->id,
                    'Salary payment — '.$payment->period,
                );
                $this->journals->addLine($header, ['gl_account_id' => $wages->id, 'debit' => $amount]);
                $this->journals->addLine($header, ['gl_account_id' => $funding->id, 'credit' => $amount]);
                $this->journals->post($header);
            });
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post salary payment {$payment->id}: {$e->getMessage()}");
        }
    }
}
