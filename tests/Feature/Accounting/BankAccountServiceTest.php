<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Accounting\BankAccountService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\SalePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLiveBusiness(string $id = 'biz-1'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    public function test_creating_a_bank_account_provisions_its_own_gl_account(): void
    {
        $businessId = $this->makeLiveBusiness();

        $bankAccount = app(BankAccountService::class)->create($businessId, 'CBZ Main Account', '1234567890', 'Harare Branch');

        $this->assertNotNull($bankAccount->gl_account_id);
        $glAccount = GlAccount::find($bankAccount->gl_account_id);
        $this->assertNotNull($glAccount);
        $this->assertSame('CBZ Main Account', $glAccount->name);
        $this->assertSame($businessId, $glAccount->business_id);
        $this->assertGreaterThanOrEqual(1011, (int) $glAccount->code);
        $this->assertLessThanOrEqual(1099, (int) $glAccount->code);
    }

    public function test_two_bank_accounts_for_the_same_business_get_collision_free_codes(): void
    {
        $businessId = $this->makeLiveBusiness();
        $service = app(BankAccountService::class);

        $first = $service->create($businessId, 'Bank One');
        $second = $service->create($businessId, 'Bank Two');

        $firstCode = GlAccount::find($first->gl_account_id)->code;
        $secondCode = GlAccount::find($second->gl_account_id)->code;

        $this->assertNotSame($firstCode, $secondCode);
    }

    public function test_deactivating_a_bank_account_does_not_delete_its_gl_account(): void
    {
        $businessId = $this->makeLiveBusiness();
        $service = app(BankAccountService::class);
        $bankAccount = $service->create($businessId, 'Old Bank');

        $service->deactivate($bankAccount);

        $this->assertFalse($bankAccount->fresh()->is_active);
        $this->assertNotNull(GlAccount::find($bankAccount->gl_account_id));
    }

    private function makeSale(string $businessId): Transaction
    {
        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 100,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 100,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => 'S-1',
        ]);

        DB::table('transactions')->where('id', $tx->id)->update(['created_at' => '2026-06-01 10:00:00']);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'product_id' => (string) Str::uuid(),
            'type' => 'sale',
            'quantity_change' => 0,
            'running_avg_cost' => 0,
            'reference_id' => $tx->id,
            'user_id' => $tx->user_id,
        ]);

        return $tx->fresh();
    }

    public function test_a_sale_tagged_with_a_bank_account_posts_against_that_accounts_own_gl_line_instead_of_the_generic_bank_account(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = app(BankAccountService::class)->create($businessId, 'CBZ Main Account');

        $tx = $this->makeSale($businessId);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'method' => 'Bank Transfer',
            'amount' => 100,
            'currency_code' => 'USD',
            'base_equivalent' => 100,
            'bank_account_id' => $bankAccount->id,
        ]);

        app(SalePostingService::class)->postIfReady($tx);

        $genericBank = GlAccount::where('business_id', $businessId)->where('code', '1010')->firstOrFail();
        $namedBank = GlAccount::find($bankAccount->gl_account_id);

        $this->assertSame(0.0, $genericBank->balance());
        $this->assertSame(100.0, $namedBank->balance());
    }

    public function test_a_sale_with_no_bank_account_specified_still_posts_to_the_generic_bank_account(): void
    {
        $businessId = $this->makeLiveBusiness();
        app(BankAccountService::class)->create($businessId, 'CBZ Main Account'); // exists but not chosen

        $tx = $this->makeSale($businessId);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'method' => 'Bank Transfer',
            'amount' => 100,
            'currency_code' => 'USD',
            'base_equivalent' => 100,
        ]);

        app(SalePostingService::class)->postIfReady($tx);

        $genericBank = GlAccount::where('business_id', $businessId)->where('code', '1010')->firstOrFail();
        $this->assertSame(100.0, $genericBank->balance());
    }
}
