<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\Tenant;
use App\Services\Accounting\CashVaultService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CashVaultServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashVaultService $vault;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = app(CashVaultService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    private function fundCash(float $amount): void
    {
        $this->fundAccount($this->account('1000'), $amount);
    }

    private function fundAccount(GlAccount $account, float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $account->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_actions_are_refused_when_accounting_is_not_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been switched on');

        $this->vault->recordTillDrop($this->businessId, 100.0, '2026-06-05', null, 'user-1');
    }
}
