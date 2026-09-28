<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\StockTakePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTakePostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockTakePostingService $posting;

    private string $businessId = 'biz-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(StockTakePostingService::class);

        Tenant::create(['id' => $this->businessId, 'business_name' => $this->businessId, 'owner_email' => 'a@example.com']);
        Business::create(['id' => $this->businessId, 'name' => $this->businessId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->businessId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->businessId)->where('code', $code)->firstOrFail();
    }

    private function makeVarianceMovement(float $quantityChange, ?string $referenceId = null, float $runningAvgCost = 5, ?string $date = null): StockMovement
    {
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->businessId, 'name' => 'Widget',
            'item_type' => 'product', 'price' => 20, 'track_stock' => true, 'is_active' => true,
        ]);

        $movement = StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->businessId,
            'product_id' => $product->id,
            'type' => 'stocktake',
            'quantity_change' => $quantityChange,
            'running_avg_cost' => $runningAvgCost,
            'reason' => 'Stock take: Q3 count',
            'reference_id' => $referenceId ?? (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
        ]);

        if ($date) {
            DB::table('stock_movements')->where('id', $movement->id)->update(['created_at' => $date]);
            $movement->refresh();
        }

        return $movement;
    }

    public function test_shrinkage_debits_stock_loss_and_credits_inventory(): void
    {
        $movement = $this->makeVarianceMovement(quantityChange: -4, runningAvgCost: 5);

        $this->posting->recordVariance($movement);

        $journal = JournalHeader::where('source_type', 'stock_take_variance')->where('source_id', $movement->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(20.0, $this->account('6050')->balance()); // Stock Loss debited (expense, debit-normal)
        // Inventory (asset, debit-normal) credited — a shrinkage reduces its balance.
        $this->assertSame(-20.0, $this->account('1200')->balance());
    }

    public function test_found_stock_debits_inventory_and_credits_stock_loss(): void
    {
        $movement = $this->makeVarianceMovement(quantityChange: 3, runningAvgCost: 5);

        $this->posting->recordVariance($movement);

        $this->assertSame(15.0, $this->account('1200')->balance()); // Inventory debited
        $this->assertSame(-15.0, $this->account('6050')->balance()); // Stock Loss credited, nets against any shrinkage
    }

    public function test_a_movement_with_no_reference_id_is_never_posted(): void
    {
        $movement = $this->makeVarianceMovement(quantityChange: -4, referenceId: null);
        $movement->update(['reference_id' => null]);

        $this->posting->recordVariance($movement->fresh());

        $this->assertSame(0, JournalHeader::where('source_type', 'stock_take_variance')->count());
    }

    public function test_non_stocktake_movement_types_are_never_posted(): void
    {
        $movement = $this->makeVarianceMovement(quantityChange: -4);
        $movement->update(['type' => 'adjustment']);

        $this->posting->recordVariance($movement->fresh());

        $this->assertSame(0, JournalHeader::where('source_type', 'stock_take_variance')->count());
    }

    public function test_a_zero_variance_movement_is_never_posted(): void
    {
        $movement = $this->makeVarianceMovement(quantityChange: 0);

        $this->posting->recordVariance($movement);

        $this->assertSame(0, JournalHeader::where('source_type', 'stock_take_variance')->count());
    }

    public function test_processing_the_same_movement_twice_does_not_double_post(): void
    {
        $movement = $this->makeVarianceMovement(quantityChange: -4, runningAvgCost: 5);

        $this->posting->recordVariance($movement);
        $this->posting->recordVariance($movement);

        $this->assertSame(1, JournalHeader::where('source_type', 'stock_take_variance')->count());
        $this->assertSame(20.0, $this->account('6050')->balance());
    }

    public function test_nothing_posts_before_the_accounting_go_live_date(): void
    {
        Business::where('id', $this->businessId)->update(['accounting_go_live_date' => '2026-07-01']);
        $movement = $this->makeVarianceMovement(quantityChange: -4, date: '2026-06-01 09:00:00');

        $this->posting->recordVariance($movement);

        $this->assertSame(0, JournalHeader::where('source_type', 'stock_take_variance')->count());
    }
}
