<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OpeningBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private OpeningBalanceService $openingBalances;

    private string $businessId = 'biz-1';

    private string $customerId;

    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openingBalances = app(OpeningBalanceService::class);
        $this->customerId = (string) Str::uuid();
        $this->supplierId = (string) Str::uuid();

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
        Customer::create(['id' => $this->customerId, 'business_id' => $this->businessId, 'name' => 'Jane Doe']);
        Supplier::create(['id' => $this->supplierId, 'business_id' => $this->businessId, 'name' => 'Acme Supplies']);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    public function test_customer_opening_balance_is_a_silent_no_op_when_accounting_is_not_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);

        $this->openingBalances->recordCustomerOpeningBalance($this->businessId, $this->customerId, 150.0, '2026-01-01');

        $this->assertSame(0.0, $this->account('1100')->balance());
    }

    public function test_customer_opening_balance_is_a_silent_no_op_for_a_zero_amount(): void
    {
        $this->openingBalances->recordCustomerOpeningBalance($this->businessId, $this->customerId, 0.0, '2026-01-01');

        $this->assertSame(0.0, $this->account('1100')->balance());
    }

    public function test_supplier_opening_balance_throws_when_accounting_is_not_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been switched on');

        $this->openingBalances->recordSupplierOpeningBalance($this->businessId, $this->supplierId, 300.0, '2026-01-01');
    }

    public function test_supplier_opening_balance_throws_for_a_zero_amount(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-zero');

        $this->openingBalances->recordSupplierOpeningBalance($this->businessId, $this->supplierId, 0.0, '2026-01-01');
    }
}
