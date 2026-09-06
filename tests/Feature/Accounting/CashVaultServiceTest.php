<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
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
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-06-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_a_till_drop_moves_money_from_cash_to_the_vault(): void
    {
        $this->fundCash(500);

        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', 'End of shift drop', 'user-1');

        $this->assertSame(300.0, $this->account('1000')->balance());
        $this->assertSame(200.0, $this->vault->balance($this->businessId));
    }

    public function test_a_bank_deposit_moves_money_from_the_vault_to_the_bank(): void
    {
        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 300.0, '2026-06-05', null, 'user-1');

        $this->vault->recordBankDeposit($this->businessId, 250.0, '2026-06-06', 'Deposited at branch', 'user-1');

        $this->assertSame(50.0, $this->vault->balance($this->businessId));
        $this->assertSame(250.0, $this->account('1010')->balance());
    }

    public function test_a_count_matching_the_ledger_posts_nothing(): void
    {
        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', null, 'user-1');

        $variance = $this->vault->recordCount($this->businessId, 200.0, '2026-06-10', 'user-1');

        $this->assertSame(0.0, $variance);
        $this->assertSame(200.0, $this->vault->balance($this->businessId));
        $this->assertSame(0.0, $this->account('6065')->balance());
    }

    public function test_a_shortfall_debits_variance_and_reduces_the_vault_to_match_reality(): void
    {
        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', null, 'user-1');

        $variance = $this->vault->recordCount($this->businessId, 180.0, '2026-06-10', 'user-1');

        $this->assertSame(-20.0, $variance);
        $this->assertSame(180.0, $this->vault->balance($this->businessId));
        $this->assertSame(20.0, $this->account('6065')->balance());
    }

    public function test_a_surplus_credits_variance_and_increases_the_vault_to_match_reality(): void
    {
        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', null, 'user-1');

        $variance = $this->vault->recordCount($this->businessId, 215.0, '2026-06-10', 'user-1');

        $this->assertSame(15.0, $variance);
        $this->assertSame(215.0, $this->vault->balance($this->businessId));
        $this->assertSame(-15.0, $this->account('6065')->balance()); // expense-normal account credited = negative
    }

    public function test_a_till_drop_that_would_overdraw_cash_is_rejected(): void
    {
        $this->fundCash(100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('negative');

        $this->vault->recordTillDrop($this->businessId, 500.0, '2026-06-05', null, 'user-1');
    }

    public function test_actions_are_refused_when_accounting_is_not_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been switched on');

        $this->vault->recordTillDrop($this->businessId, 100.0, '2026-06-05', null, 'user-1');
    }

    public function test_activity_lists_movements_chronologically_with_a_running_balance(): void
    {
        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', 'Drop 1', 'user-1');
        $this->vault->recordBankDeposit($this->businessId, 50.0, '2026-06-06', 'Bank it', 'user-1');

        $activity = $this->vault->activity($this->businessId);

        $this->assertCount(2, $activity);
        $this->assertSame([200.0, 150.0], array_map(fn ($r) => $r['running_balance'], $activity));
        $this->assertSame('Drop 1', $activity[0]['description']);
    }

    public function test_backfills_the_vault_account_for_a_business_seeded_before_this_feature_existed(): void
    {
        // Simulate an "old" seeded chart missing Cash Vault/Cash Vault
        // Variance entirely, matching a business seeded before part C shipped.
        GlAccount::where('business_id', $this->businessId)->where('code', '1005')->delete();
        GlAccount::where('business_id', $this->businessId)->where('code', '6065')->delete();

        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', null, 'user-1');

        $this->assertNotNull($this->account('1005'));
        $this->assertSame(200.0, $this->vault->balance($this->businessId));
    }

    public function test_journal_source_type_is_tagged_for_each_action(): void
    {
        $this->fundCash(500);
        $this->vault->recordTillDrop($this->businessId, 200.0, '2026-06-05', null, 'user-1');

        $this->assertSame(1, JournalHeader::where('source_type', 'cash_vault_drop')->count());
    }
}
