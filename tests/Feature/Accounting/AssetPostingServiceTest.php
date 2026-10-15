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

    public function test_acquisition_is_not_posted_before_the_business_goes_live(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => null]);
        $asset = $this->makeAsset();

        $this->postings->recordAcquisition($asset);

        $this->assertSame(0, JournalHeader::where('business_id', $this->businessId)->count());
    }
}
