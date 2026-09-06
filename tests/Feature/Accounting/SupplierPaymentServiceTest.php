<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PartyLedgerService;
use App\Services\Accounting\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SupplierPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupplierPaymentService $payments;

    private string $businessId = 'biz-1';

    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = app(SupplierPaymentService::class);
        $this->supplierId = (string) Str::uuid();

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
        Supplier::create(['id' => $this->supplierId, 'business_id' => $this->businessId, 'name' => 'Acme Supplies']);

        // Fund the till and raise a payable so a payment has both cash
        // to draw from and a balance to reduce.
        $this->fundCash(1000);
        $this->raisePayable(200);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    private function fundCash(float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    private function raisePayable(float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-06-01', 'supplier_invoice', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('2010')->id, 'debit' => $amount]);
        $journals->addLine($header, [
            'gl_account_id' => $this->account('2000')->id, 'credit' => $amount,
            'party_type' => 'supplier', 'party_id' => $this->supplierId,
        ]);
        $journals->post($header);
    }

    public function test_a_cash_payment_debits_payable_and_credits_cash(): void
    {
        $this->payments->recordPayment($this->businessId, $this->supplierId, 80.0, '2026-06-10', 'cash', 'receipt-1', 'user-1');

        $this->assertSame(120.0, $this->account('2000')->balance()); // 200 - 80
        $this->assertSame(920.0, $this->account('1000')->balance()); // 1000 - 80

        $line = GeneralLedgerEntry::where('gl_account_id', $this->account('2000')->id)
            ->orderByDesc('created_at')->first();
        $this->assertSame('supplier', $line->party_type);
        $this->assertSame($this->supplierId, $line->party_id);
    }

    public function test_a_bank_payment_credits_bank_not_cash(): void
    {
        // Bank is must_be_positive too — fund it first, same as Cash.
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1010')->id, 'debit' => 200]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => 200]);
        $journals->post($header);

        $this->payments->recordPayment($this->businessId, $this->supplierId, 50.0, '2026-06-10', 'bank');

        $this->assertSame(1000.0, $this->account('1000')->balance()); // Cash untouched
        $this->assertSame(150.0, $this->account('1010')->balance()); // Bank drawn down instead
    }

    public function test_supplier_balance_reflects_the_payment(): void
    {
        $this->payments->recordPayment($this->businessId, $this->supplierId, 80.0, '2026-06-10');

        $ledger = app(PartyLedgerService::class);
        $this->assertSame(120.0, $ledger->currentBalance($this->businessId, 'supplier', $this->supplierId));
    }

    public function test_payment_is_refused_when_accounting_is_not_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been switched on');

        $this->payments->recordPayment($this->businessId, $this->supplierId, 50.0, '2026-06-10');
    }

    public function test_a_payment_that_would_overdraw_cash_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('negative');

        $this->payments->recordPayment($this->businessId, $this->supplierId, 5000.0, '2026-06-10');
    }
}
