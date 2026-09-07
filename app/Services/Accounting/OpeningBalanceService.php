<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use RuntimeException;

/**
 * Posts the one-time "this party already owed us / we already owed them
 * this much before the books started" entry — the counterpart
 * SalePostingService's cutover check refers to ("pre-dates the cutover —
 * covered by opening balances instead, not auto-posted"). Offsets always
 * land on a dedicated Opening Balance Equity account (code 3020, backfilled
 * on demand via ChartOfAccountsSeeder::ensureAccount — same pattern
 * SupplierInvoiceService uses for Purchase Price Variance) rather than
 * Retained Earnings, so an accountant can see exactly what came in as a
 * balance-forward figure versus what the business actually earned.
 *
 * One-time per party: a second call for the same customer/supplier is a
 * silent no-op (customer side, arriving from an offline device that might
 * retry) or a thrown error (supplier side, a manual BackOffice action with
 * someone waiting on the result) — see the two public methods.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ChartOfAccountsSeeder $chartSeeder,
    ) {}

    /**
     * Called from SyncProcessor when a till posts a `credit_transactions`
     * row of type 'opening_balance'. Deliberately as tolerant as
     * SalePostingService: a synced ledger row is the source of truth on its
     * own, so a posting problem here must never surface to the till or
     * block sync — it just leaves the formal books without this entry until
     * accounting is switched on or the chart is fixed up.
     */
    public function recordCustomerOpeningBalance(
        string $businessId,
        string $customerId,
        float $amount,
        string $asOfDate,
        ?string $notes = null,
    ): void {
        if (abs($amount) < 0.005) {
            return;
        }

        $business = Business::find($businessId);
        if (! $business?->accountingIsLive()) {
            return;
        }

        if ($this->alreadyPosted($businessId, 'opening_balance_customer', $customerId)) {
            return;
        }

        $receivable = GlAccount::where('business_id', $businessId)->where('code', '1100')->first();
        if (! $receivable) {
            return;
        }

        $equity = $this->openingBalanceEquityAccount($businessId);

        $header = $this->journals->createDraft(
            $businessId,
            $asOfDate,
            'opening_balance_customer',
            $customerId,
            $notes ?? 'Customer opening balance',
        );

        $this->journals->addLine($header, [
            'gl_account_id' => $receivable->id,
            'debit' => max(0, $amount),
            'credit' => max(0, -$amount),
            'party_type' => 'customer',
            'party_id' => $customerId,
        ]);
        $this->journals->addLine($header, [
            'gl_account_id' => $equity->id,
            'credit' => max(0, $amount),
            'debit' => max(0, -$amount),
        ]);

        $this->journals->post($header);
    }

    /**
     * A manual BackOffice action — throws rather than silently skipping,
     * since there's a person waiting on the result (same reasoning as
     * SupplierInvoiceService/SupplierPaymentService).
     */
    public function recordSupplierOpeningBalance(
        string $businessId,
        string $supplierId,
        float $amount,
        string $asOfDate,
        ?string $notes = null,
        ?string $userId = null,
    ): JournalHeader {
        $business = Business::find($businessId);
        if (! $business?->accountingIsLive()) {
            throw new RuntimeException('Accounting has not been switched on for this business yet.');
        }

        if ($this->alreadyPosted($businessId, 'opening_balance_supplier', $supplierId)) {
            throw new RuntimeException('An opening balance has already been recorded for this supplier.');
        }

        if (abs($amount) < 0.005) {
            throw new RuntimeException('Enter a non-zero opening balance.');
        }

        $payable = GlAccount::where('business_id', $businessId)->where('code', '2000')->first();
        if (! $payable) {
            throw new RuntimeException('Chart of accounts is missing Accounts Payable.');
        }

        $equity = $this->openingBalanceEquityAccount($businessId);

        $header = $this->journals->createDraft(
            $businessId,
            $asOfDate,
            'opening_balance_supplier',
            $supplierId,
            $notes ?? 'Supplier opening balance',
        );

        $this->journals->addLine($header, [
            'gl_account_id' => $equity->id,
            'debit' => max(0, $amount),
            'credit' => max(0, -$amount),
        ]);
        $this->journals->addLine($header, [
            'gl_account_id' => $payable->id,
            'credit' => max(0, $amount),
            'debit' => max(0, -$amount),
            'party_type' => 'supplier',
            'party_id' => $supplierId,
        ]);

        return $this->journals->post($header, $userId);
    }

    private function alreadyPosted(string $businessId, string $sourceType, string $sourceId): bool
    {
        return JournalHeader::where('business_id', $businessId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', '!=', 'reversed')
            ->exists();
    }

    private function openingBalanceEquityAccount(string $businessId): GlAccount
    {
        return $this->chartSeeder->ensureAccount(
            $businessId,
            'Equity',
            "Shareholders' Equity",
            ['code' => '3020', 'name' => 'Opening Balance Equity'],
        );
    }
}
