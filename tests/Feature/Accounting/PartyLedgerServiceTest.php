<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PartyLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartyLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartyLedgerService $ledger;

    private JournalService $journals;

    private string $businessId = 'biz-1';

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(PartyLedgerService::class);
        $this->journals = app(JournalService::class);
        $this->customerId = (string) Str::uuid();

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    /** Posts a simple two-line journal: Dr Accounts Receivable (tagged to the customer) / Cr Revenue, on $date. */
    private function postCharge(string $date, float $amount, ?string $description = null): JournalHeader
    {
        $header = $this->journals->createDraft($this->businessId, $date, 'sale', (string) Str::uuid(), $description);
        $this->journals->addLine($header, [
            'gl_account_id' => $this->account('1100')->id,
            'debit' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->customerId,
        ]);
        $this->journals->addLine($header, ['gl_account_id' => $this->account('4000')->id, 'credit' => $amount]);

        return $this->journals->post($header);
    }

    /** Posts a payment: Dr Cash / Cr Accounts Receivable (tagged to the customer), on $date. */
    private function postPayment(string $date, float $amount): JournalHeader
    {
        $header = $this->journals->createDraft($this->businessId, $date, 'payment', (string) Str::uuid());
        $this->journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $this->journals->addLine($header, [
            'gl_account_id' => $this->account('1100')->id,
            'credit' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->customerId,
        ]);

        return $this->journals->post($header);
    }

    public function test_statement_lists_lines_chronologically_with_a_running_balance(): void
    {
        $this->postCharge('2026-06-01', 100, 'Sale #1');
        $this->postPayment('2026-06-05', 40);
        $this->postCharge('2026-06-10', 25, 'Sale #2');

        $statement = $this->ledger->statement($this->businessId, 'customer', $this->customerId);

        $this->assertSame(0.0, $statement['opening_balance']);
        $this->assertSame(85.0, $statement['closing_balance']);
        $this->assertCount(3, $statement['lines']);
        $this->assertSame([100.0, 60.0, 85.0], array_map(
            fn ($l) => $l['running_balance'],
            $statement['lines']
        ));
        $this->assertSame('Sale #1', $statement['lines'][0]['description']);
    }

    public function test_statement_carries_an_opening_balance_from_before_the_from_date(): void
    {
        $this->postCharge('2026-05-01', 100);
        $this->postPayment('2026-05-15', 30);
        $this->postCharge('2026-06-10', 20);

        $statement = $this->ledger->statement($this->businessId, 'customer', $this->customerId, fromDate: '2026-06-01');

        $this->assertSame(70.0, $statement['opening_balance']);
        $this->assertCount(1, $statement['lines']); // only the June 10 charge falls inside the window
        $this->assertSame(90.0, $statement['closing_balance']);
    }

    public function test_current_balance_matches_the_control_account(): void
    {
        $this->postCharge('2026-06-01', 100);
        $this->postPayment('2026-06-05', 40);

        $this->assertSame(60.0, $this->ledger->currentBalance($this->businessId, 'customer', $this->customerId));
        $this->assertSame(60.0, $this->account('1100')->balance());
    }

    public function test_a_single_old_unpaid_charge_ages_into_the_correct_bucket(): void
    {
        $this->postCharge('2026-01-01', 500); // ~247 days before the as-of date below

        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId, asOfDate: '2026-09-05');

        $this->assertSame(500.0, $aging['buckets']['over_120']);
        $this->assertSame(0.0, $aging['buckets']['current']);
        $this->assertSame(500.0, $aging['total_outstanding']);
        $this->assertSame(0.0, $aging['credit_balance']);
    }

    public function test_a_payment_settles_the_oldest_charge_first_fifo(): void
    {
        $this->postCharge('2026-01-01', 100); // old — would land in over_120
        $this->postCharge('2026-08-20', 100); // recent — current bucket
        $this->postPayment('2026-09-01', 100); // pays off the OLD charge first, not the new one

        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId, asOfDate: '2026-09-05');

        $this->assertSame(0.0, $aging['buckets']['over_120'], 'the old charge should be fully settled first');
        $this->assertSame(100.0, $aging['buckets']['current'], 'the recent charge should remain fully outstanding');
        $this->assertSame(100.0, $aging['total_outstanding']);
    }

    public function test_a_partial_payment_reduces_the_oldest_charge_only(): void
    {
        $this->postCharge('2026-01-01', 100);
        $this->postCharge('2026-08-20', 50);
        $this->postPayment('2026-09-01', 60); // fully pays the old charge (100) — wait, only 60, so partially

        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId, asOfDate: '2026-09-05');

        $this->assertSame(40.0, $aging['buckets']['over_120'], 'old charge reduced by the payment, 100 - 60');
        $this->assertSame(50.0, $aging['buckets']['current'], 'newer charge untouched — FIFO settles oldest first');
        $this->assertSame(90.0, $aging['total_outstanding']);
    }

    public function test_an_overpayment_is_reported_as_a_credit_balance_not_a_negative_bucket(): void
    {
        $this->postCharge('2026-08-20', 50);
        $this->postPayment('2026-09-01', 80); // pays the 50 charge and overpays by 30

        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId, asOfDate: '2026-09-05');

        $this->assertSame(0.0, $aging['total_outstanding']);
        $this->assertSame(30.0, $aging['credit_balance']);
        foreach ($aging['buckets'] as $bucket => $amount) {
            $this->assertSame(0.0, $amount, "bucket {$bucket} should be zero, never negative");
        }
    }

    public function test_a_party_with_no_activity_has_a_zero_balance_and_empty_statement(): void
    {
        $statement = $this->ledger->statement($this->businessId, 'customer', $this->customerId);
        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId);

        $this->assertSame(0.0, $statement['closing_balance']);
        $this->assertEmpty($statement['lines']);
        $this->assertSame(0.0, $aging['total_outstanding']);
    }

    public function test_a_voided_sales_reversal_removes_the_original_charge_from_aging(): void
    {
        // JournalService::reverse() always dates the reversal "today" (real
        // wall-clock time, not a fixture date) — the charge must predate
        // that, and the aging as-of date must not.
        $original = $this->postCharge(now()->subDay()->toDateString(), 75);

        $asOf = now()->toDateString();
        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId, asOfDate: $asOf);
        $this->assertSame(75.0, $aging['total_outstanding']);

        $this->journals->reverse($original, null, 'test reversal');

        $aging = $this->ledger->agingBuckets($this->businessId, 'customer', $this->customerId, asOfDate: $asOf);
        $this->assertSame(0.0, $aging['total_outstanding']);
        $this->assertSame(0.0, $this->ledger->currentBalance($this->businessId, 'customer', $this->customerId));
    }

    public function test_two_customers_ledgers_never_mix(): void
    {
        $otherCustomerId = (string) Str::uuid();
        $this->postCharge('2026-06-01', 100);

        $header = $this->journals->createDraft($this->businessId, '2026-06-01', 'sale', (string) Str::uuid());
        $this->journals->addLine($header, [
            'gl_account_id' => $this->account('1100')->id, 'debit' => 250,
            'party_type' => 'customer', 'party_id' => $otherCustomerId,
        ]);
        $this->journals->addLine($header, ['gl_account_id' => $this->account('4000')->id, 'credit' => 250]);
        $this->journals->post($header);

        $this->assertSame(100.0, $this->ledger->currentBalance($this->businessId, 'customer', $this->customerId));
        $this->assertSame(250.0, $this->ledger->currentBalance($this->businessId, 'customer', $otherCustomerId));
    }

    // ─── Supplier (creditor) side ────────────────────────────────────────────
    // Accounts Payable is credit-normal, the opposite of Accounts
    // Receivable — these exist specifically to catch the sign inverting
    // incorrectly for a supplier, which happened for real (see
    // PartyLedgerService's sign() doc comment) once Part B of the
    // Purchasing & Cash Vault Blueprint started posting real supplier data.

    /** Dr GRN Suspense / Cr Accounts Payable (tagged to the supplier) — a purchase, on $date. */
    private function postSupplierCharge(string $supplierId, string $date, float $amount): JournalHeader
    {
        $header = $this->journals->createDraft($this->businessId, $date, 'supplier_invoice', (string) Str::uuid());
        $this->journals->addLine($header, ['gl_account_id' => $this->account('2010')->id, 'debit' => $amount]);
        $this->journals->addLine($header, [
            'gl_account_id' => $this->account('2000')->id,
            'credit' => $amount,
            'party_type' => 'supplier',
            'party_id' => $supplierId,
        ]);

        return $this->journals->post($header);
    }

    /** Dr Cash / Cr Owner's Capital — funds the till so a supplier payment doesn't push Cash negative. */
    private function fundCash(string $date, float $amount): void
    {
        $header = $this->journals->createDraft($this->businessId, $date, 'capital', (string) Str::uuid());
        $this->journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $this->journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $this->journals->post($header);
    }

    /** Dr Accounts Payable (tagged to the supplier) / Cr Cash — a payment, on $date. */
    private function postSupplierPayment(string $supplierId, string $date, float $amount): JournalHeader
    {
        $this->fundCash($date, $amount);

        $header = $this->journals->createDraft($this->businessId, $date, 'supplier_payment', (string) Str::uuid());
        $this->journals->addLine($header, [
            'gl_account_id' => $this->account('2000')->id,
            'debit' => $amount,
            'party_type' => 'supplier',
            'party_id' => $supplierId,
        ]);
        $this->journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'credit' => $amount]);

        return $this->journals->post($header);
    }

    public function test_a_supplier_charge_shows_as_a_positive_balance_owed_not_negative(): void
    {
        $supplierId = (string) Str::uuid();
        $this->postSupplierCharge($supplierId, '2026-06-01', 100);

        $this->assertSame(100.0, $this->ledger->currentBalance($this->businessId, 'supplier', $supplierId));
    }

    public function test_a_supplier_payment_reduces_the_balance_owed(): void
    {
        $supplierId = (string) Str::uuid();
        $this->postSupplierCharge($supplierId, '2026-06-01', 100);
        $this->postSupplierPayment($supplierId, '2026-06-05', 40);

        $this->assertSame(60.0, $this->ledger->currentBalance($this->businessId, 'supplier', $supplierId));
    }

    public function test_supplier_statement_running_balance_increases_on_charge_and_decreases_on_payment(): void
    {
        $supplierId = (string) Str::uuid();
        $this->postSupplierCharge($supplierId, '2026-06-01', 100);
        $this->postSupplierPayment($supplierId, '2026-06-05', 40);

        $statement = $this->ledger->statement($this->businessId, 'supplier', $supplierId);

        $this->assertSame([100.0, 60.0], array_map(fn ($l) => $l['running_balance'], $statement['lines']));
        $this->assertSame(60.0, $statement['closing_balance']);
    }

    public function test_a_supplier_payment_settles_the_oldest_charge_first_fifo(): void
    {
        $supplierId = (string) Str::uuid();
        $this->postSupplierCharge($supplierId, '2026-01-01', 100); // old — would land in over_120
        $this->postSupplierCharge($supplierId, '2026-08-20', 100); // recent — current bucket
        $this->postSupplierPayment($supplierId, '2026-09-01', 100);

        $aging = $this->ledger->agingBuckets($this->businessId, 'supplier', $supplierId, asOfDate: '2026-09-05');

        $this->assertSame(0.0, $aging['buckets']['over_120'], 'the old charge should be fully settled first');
        $this->assertSame(100.0, $aging['buckets']['current']);
        $this->assertSame(100.0, $aging['total_outstanding']);
    }

    public function test_overpaying_a_supplier_is_a_credit_balance_not_a_negative_bucket(): void
    {
        $supplierId = (string) Str::uuid();
        $this->postSupplierCharge($supplierId, '2026-08-20', 50);
        $this->postSupplierPayment($supplierId, '2026-09-01', 80);

        $aging = $this->ledger->agingBuckets($this->businessId, 'supplier', $supplierId, asOfDate: '2026-09-05');

        $this->assertSame(0.0, $aging['total_outstanding']);
        $this->assertSame(30.0, $aging['credit_balance']);
    }

    public function test_a_customer_and_a_supplier_with_the_same_id_never_mix(): void
    {
        $sharedId = (string) Str::uuid();
        $this->postSupplierCharge($sharedId, '2026-06-01', 100);

        $header = $this->journals->createDraft($this->businessId, '2026-06-01', 'sale', (string) Str::uuid());
        $this->journals->addLine($header, [
            'gl_account_id' => $this->account('1100')->id, 'debit' => 40,
            'party_type' => 'customer', 'party_id' => $sharedId,
        ]);
        $this->journals->addLine($header, ['gl_account_id' => $this->account('4000')->id, 'credit' => 40]);
        $this->journals->post($header);

        $this->assertSame(100.0, $this->ledger->currentBalance($this->businessId, 'supplier', $sharedId));
        $this->assertSame(40.0, $this->ledger->currentBalance($this->businessId, 'customer', $sharedId));
    }
}
