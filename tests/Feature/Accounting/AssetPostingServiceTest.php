<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Asset;
use App\Models\Business;
use App\Models\Tenant;
use App\Services\Accounting\AssetPostingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssetPostingService $postings;

    private string $businessId = 'biz-assets-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->postings = app(AssetPostingService::class);

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
        $header = $journals->createDraft($this->businessId, '2026-01-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    private function makeAsset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'name' => 'Delivery Van',
            'acquisition_date' => '2026-01-01',
            'acquisition_cost' => 12000,
            'salvage_value' => 0,
            'useful_life_months' => 24,
            'funding_method' => 'cash',
            'status' => 'active',
            'created_by_user_id' => (string) Str::uuid(),
        ], $overrides));
    }

    public function test_recording_an_acquisition_debits_fixed_assets_and_credits_cash(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset();

        $this->postings->recordAcquisition($asset);

        $this->assertSame(12000.0, $this->account('1500')->balance());
        $this->assertSame(8000.0, $this->account('1000')->balance());
    }

    public function test_recording_an_acquisition_paid_from_bank_credits_bank_not_cash(): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->businessId, '2026-01-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1010')->id, 'debit' => 20000]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => 20000]);
        $journals->post($header);

        $asset = $this->makeAsset(['funding_method' => 'bank']);
        $this->postings->recordAcquisition($asset);

        $this->assertSame(12000.0, $this->account('1500')->balance());
        $this->assertSame(8000.0, $this->account('1010')->balance());
    }

    public function test_acquisition_is_not_posted_before_the_business_goes_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);
        $asset = $this->makeAsset();

        $this->postings->recordAcquisition($asset);

        $this->assertSame(0, JournalHeader::where('business_id', $this->businessId)->count());
    }

    public function test_monthly_depreciation_catches_up_every_elapsed_month(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset(['acquisition_date' => Carbon::now()->subMonths(5)->toDateString()]);
        $this->postings->recordAcquisition($asset);

        $posted = $this->postings->postMonthlyDepreciation($this->businessId);

        $this->assertSame(5, $posted);
        // Diminishing balance at the 200%-declining default rate (2/24 per
        // month) applied to the shrinking book value each month, not a flat
        // 500/month: 1000, 916.6667, 840.2778, 770.2546, 706.0667.
        $this->assertSame(4233.2658, $asset->accumulatedDepreciation($this->businessId));
        $this->assertSame(7766.7342, $asset->bookValue($this->businessId));
    }

    public function test_monthly_depreciation_never_exceeds_the_useful_life(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset([
            'useful_life_months' => 3,
            'acquisition_date' => Carbon::now()->subMonths(10)->toDateString(),
        ]);
        $this->postings->recordAcquisition($asset);

        $this->postings->postMonthlyDepreciation($this->businessId);

        $this->assertSame(3, JournalHeader::where('source_type', 'depreciation')->where('source_id', $asset->id)->count());
        $this->assertSame(12000.0, $asset->accumulatedDepreciation($this->businessId));
    }

    public function test_running_the_sweep_twice_never_double_posts(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset(['acquisition_date' => Carbon::now()->subMonths(3)->toDateString()]);
        $this->postings->recordAcquisition($asset);

        $first = $this->postings->postMonthlyDepreciation($this->businessId);
        $second = $this->postings->postMonthlyDepreciation($this->businessId);

        $this->assertSame(3, $first);
        $this->assertSame(0, $second);
        $this->assertSame(3, JournalHeader::where('source_type', 'depreciation')->where('source_id', $asset->id)->count());
    }

    public function test_a_future_dated_acquisition_accrues_no_depreciation_yet(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset(['acquisition_date' => Carbon::now()->addMonths(2)->toDateString()]);
        $this->postings->recordAcquisition($asset);

        $posted = $this->postings->postMonthlyDepreciation($this->businessId);

        $this->assertSame(0, $posted);
    }

    public function test_disposal_at_a_gain_credits_the_variance_account(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset(['acquisition_date' => Carbon::now()->subMonths(5)->toDateString()]);
        $this->postings->recordAcquisition($asset);
        $this->postings->postMonthlyDepreciation($this->businessId);
        // book value = 12000 - 4233.2658 = 7766.7342 (diminishing balance, see the catch-up test)

        $this->postings->recordDisposal($asset, now()->toDateString(), 10500.0);

        $this->assertSame(0.0, $this->account('1500')->balance());
        $this->assertSame(0.0, $asset->accumulatedDepreciation($this->businessId));
        $variance = GlAccount::where('business_id', $this->businessId)->where('code', '6075')->first();
        $this->assertNotNull($variance);
        $this->assertSame(-2733.2658, $variance->balance()); // credit-side gain shows as negative on a debit-normal account
    }

    public function test_disposal_at_a_loss_debits_the_variance_account(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset(['acquisition_date' => Carbon::now()->subMonths(5)->toDateString()]);
        $this->postings->recordAcquisition($asset);
        $this->postings->postMonthlyDepreciation($this->businessId);
        // book value = 7766.7342 (diminishing balance, see the catch-up test)

        $this->postings->recordDisposal($asset, now()->toDateString(), 4000.0);

        $variance = GlAccount::where('business_id', $this->businessId)->where('code', '6075')->first();
        $this->assertSame(3766.7342, $variance->balance());
    }

    public function test_disposal_with_no_accumulated_depreciation_and_proceeds_equal_to_cost_balances_cleanly(): void
    {
        $this->fundCash(20000);
        $asset = $this->makeAsset(); // acquired "today" — nothing depreciated yet
        $this->postings->recordAcquisition($asset);

        $this->postings->recordDisposal($asset, now()->toDateString(), 12000.0);

        $this->assertSame(0.0, $this->account('1500')->balance());
        $this->assertSame(20000.0, $this->account('1000')->balance()); // -12000 acquisition + 12000 proceeds back
    }
}
