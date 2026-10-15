<?php

namespace Tests\Feature\Accounting;

use App\Exceptions\ServerPostingRetiredException;
use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\CashVaultService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\Accounting\ProductOpeningStockPostingService;
use App\Services\Accounting\SalePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The server never posts journals: the device that performs a transaction
 * posts it, and the server only checks, stores and relays it
 * (JournalBundleIngestor). Each retired server entry point either stands
 * down or refuses with ServerPostingRetiredException — never books.
 */
class ServerPostingRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-retired';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => 'o@example.com', 'pairing_code' => 'RTRD01']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', self::BIZ)->where('code', $code)->firstOrFail();
    }

    private function assertNoJournals(): void
    {
        $this->assertSame(0, JournalHeader::where('business_id', self::BIZ)->count());
    }

    private function unpostedSale(): Transaction
    {
        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => self::BIZ,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 40,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 40,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => 'S-1',
        ]);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'method' => 'Cash',
            'amount' => 40,
            'currency_code' => 'USD',
            'base_equivalent' => 40,
        ]);

        return $tx;
    }

    public function test_the_device_owns_posting_for_every_date(): void
    {
        $business = Business::find(self::BIZ);

        $this->assertNull($business->client_gl_posting_enabled_at);
        $this->assertTrue($business->postsFromClientFor('2020-01-01'));
        $this->assertTrue($business->postsFromClientFor(now()->toDateString()));
    }

    public function test_the_journal_engine_refuses_to_create_post_or_reverse(): void
    {
        $journals = app(JournalService::class);
        $header = new JournalHeader(['business_id' => self::BIZ, 'journal_number' => 'X', 'status' => 'draft']);

        foreach ([
            fn () => $journals->createDraft(self::BIZ, '2026-06-01', 'manual', null, 'x'),
            fn () => $journals->post($header),
            fn () => $journals->reverse($header),
        ] as $call) {
            try {
                $call();
                $this->fail('The server posted a journal.');
            } catch (ServerPostingRetiredException $e) {
                $this->assertStringContainsString('posted on a SmartPOS device', $e->getMessage());
            }
        }
        $this->assertNoJournals();
    }

    public function test_a_synced_sale_gets_no_server_journal(): void
    {
        app(SalePostingService::class)->postIfReady($this->unpostedSale());

        $this->assertNoJournals();
    }

    public function test_take_on_stock_reaching_the_server_is_not_booked_there(): void
    {
        $movement = StockMovement::create([
            'business_id' => self::BIZ,
            'product_id' => (string) Str::uuid(),
            'type' => 'opening_stock',
            'quantity_change' => 10,
            'unit_cost' => 5,
            'user_id' => (string) Str::uuid(),
        ]);

        app(ProductOpeningStockPostingService::class)->recordTakeOn($movement);

        $this->assertNoJournals();
    }

    public function test_the_cash_vault_refuses_to_post(): void
    {
        $this->expectException(ServerPostingRetiredException::class);

        try {
            app(CashVaultService::class)->recordTillDrop(self::BIZ, 50, '2026-06-01', null, null);
        } finally {
            $this->assertNoJournals();
        }
    }

    public function test_a_supplier_opening_balance_is_refused(): void
    {
        $this->expectException(ServerPostingRetiredException::class);

        try {
            app(OpeningBalanceService::class)->recordSupplierOpeningBalance(self::BIZ, (string) Str::uuid(), 100, '2026-01-01');
        } finally {
            $this->assertNoJournals();
        }
    }

    public function test_a_back_office_manual_journal_is_refused_with_a_message(): void
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'is_active' => true]);
        session(['backoffice' => [
            'tenant_id' => self::BIZ, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => 'business_owner', 'business_name' => self::BIZ,
            'currency_code' => 'USD',
        ]]);

        $response = $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Rent accrual',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 100, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 100],
            ],
        ]);

        $response->assertSessionHasErrors('journal');
        $this->assertStringContainsString('posted on a SmartPOS device', session('errors')->first('journal'));
        $this->assertNoJournals();
    }

    public function test_the_pending_posting_commands_only_report(): void
    {
        $this->unpostedSale();

        $this->artisan('accounting:post-pending-sales')
            ->expectsOutputToContain('Found 1 unposted sale(s)')
            ->assertSuccessful();
        foreach ([
            'accounting:post-pending-credit-payments',
            'accounting:post-pending-invoice-payments',
            'accounting:post-pending-supplier-payments',
            'accounting:post-pending-salary-payments',
            'accounting:post-pending-asset-transactions',
            'accounting:post-pending-payroll',
        ] as $command) {
            $this->artisan($command)->expectsOutputToContain('The server no longer posts')->assertSuccessful();
        }

        $this->assertNoJournals();
    }

    public function test_the_depreciation_command_is_retired(): void
    {
        $this->artisan('accounting:post-asset-depreciation')
            ->expectsOutputToContain('the server no longer posts')
            ->assertSuccessful();

        $this->assertNoJournals();
    }
}
