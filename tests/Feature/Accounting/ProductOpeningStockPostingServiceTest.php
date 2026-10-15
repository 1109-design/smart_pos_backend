<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\ProductOpeningStockPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductOpeningStockPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductOpeningStockPostingService $posting;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(ProductOpeningStockPostingService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    private function makeOpeningStockMovement(float $quantityChange, float $unitCost = 5, ?string $date = null): StockMovement
    {
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->businessId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'cost_price' => $unitCost, 'track_stock' => true, 'is_active' => true,
        ]);

        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'product_id' => $product->id,
            'type' => 'opening_stock',
            'quantity_change' => $quantityChange,
            'unit_cost' => $unitCost,
            'reason' => 'Opening stock (take-on)',
            'user_id' => (string) Str::uuid(),
        ]);

        if ($date) {
            DB::table('stock_movements')->where('id', $movement->id)->update(['created_at' => $date]);
            $movement->refresh();
        }

        return $movement;
    }

    public function test_non_opening_stock_movement_types_are_never_posted(): void
    {
        $movement = $this->makeOpeningStockMovement(quantityChange: 10);
        $movement->update(['type' => 'adjustment']);

        $this->posting->recordTakeOn($movement->fresh());

        $this->assertSame(0, JournalHeader::where('source_type', 'product_opening_stock')->count());
    }

    public function test_a_zero_value_movement_is_never_posted(): void
    {
        $movement = $this->makeOpeningStockMovement(quantityChange: 10, unitCost: 0);

        $this->posting->recordTakeOn($movement);

        $this->assertSame(0, JournalHeader::where('source_type', 'product_opening_stock')->count());
    }

    public function test_nothing_posts_before_the_accounting_go_live_date(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => '2026-07-01']);
        $movement = $this->makeOpeningStockMovement(quantityChange: 10, date: '2026-06-01 09:00:00');

        $this->posting->recordTakeOn($movement);

        $this->assertSame(0, JournalHeader::where('source_type', 'product_opening_stock')->count());
    }
}
