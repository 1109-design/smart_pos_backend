<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchasing & Cash Vault Blueprint, part B — Dr Accounts Payable / Cr Cash
 * or Bank. Not tied to a specific invoice: matches the same "one running
 * balance, FIFO-aged" simplification Phase 11c's PartyLedgerService already
 * uses for debtors — the payment reduces the supplier's overall balance,
 * and aging settles the oldest outstanding charge first.
 */
class SupplierPaymentService
{
    public function __construct(private readonly JournalService $journals) {}

    public function recordPayment(
        string $businessId,
        string $supplierId,
        float $amount,
        string $paymentDate,
        string $method = 'cash',
        ?string $reference = null,
        ?string $userId = null,
    ): SupplierPayment {
        $business = Business::find($businessId);
        if (! $business?->accountingIsLive()) {
            throw new RuntimeException('Accounting has not been switched on for this business yet.');
        }

        $cashAccountCode = $method === 'bank' ? '1010' : '1000';
        $cashAccount = GlAccount::where('business_id', $businessId)->where('code', $cashAccountCode)->first();
        $accountsPayable = GlAccount::where('business_id', $businessId)->where('code', '2000')->first();

        if (! $cashAccount || ! $accountsPayable) {
            throw new RuntimeException('Chart of accounts is missing Cash/Bank or Accounts Payable.');
        }

        return DB::transaction(function () use ($businessId, $supplierId, $amount, $paymentDate, $method, $reference, $userId, $cashAccount, $accountsPayable) {
            $payment = SupplierPayment::create([
                'business_id' => $businessId,
                'supplier_id' => $supplierId,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference' => $reference,
                'recorded_by_user_id' => $userId,
            ]);

            $header = $this->journals->createDraft(
                $businessId,
                $paymentDate,
                'supplier_payment',
                $payment->id,
                'Payment to supplier'.($reference ? " ({$reference})" : ''),
            );

            $this->journals->addLine($header, [
                'gl_account_id' => $accountsPayable->id,
                'debit' => $amount,
                'party_type' => 'supplier',
                'party_id' => $supplierId,
            ]);
            $this->journals->addLine($header, ['gl_account_id' => $cashAccount->id, 'credit' => $amount]);

            $this->journals->post($header);

            return $payment;
        });
    }
}
