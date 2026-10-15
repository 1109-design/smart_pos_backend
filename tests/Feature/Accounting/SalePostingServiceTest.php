<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\SalePostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalePostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SalePostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posting = app(SalePostingService::class);
    }

    /** Business with a seeded chart, live from 2026-01-01, unless told otherwise. */
    private function makeLiveBusiness(string $id = 'biz-1', ?string $goLive = '2026-01-01'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD', 'accounting_go_live_date' => $goLive]);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    private function makeSale(
        string $businessId,
        float $subtotal,
        float $tax,
        float $total,
        string $status = 'completed',
        ?string $customerId = null,
        ?string $createdAt = null,
        float $discount = 0,
    ): Transaction {
        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'user_id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'discount_total' => $discount,
            'total' => $total,
            'base_currency' => 'USD',
            'status' => $status,
            'sale_number' => 'S-1',
        ]);

        // Eloquent's automatic timestamps overwrite a created_at passed into
        // create() itself, and disabling $timestamps to work around it also
        // disables Carbon casting for the column entirely (see the real fix
        // in SyncProcessor's 'transactions' case) — a raw query update is
        // the only way to backdate this that doesn't quietly break the
        // column's type on every future read.
        DB::table('transactions')->where('id', $tx->id)->update([
            'created_at' => $createdAt ?? '2026-06-01 10:00:00',
        ]);

        return $tx->fresh();
    }

    private function addItem(Transaction $tx, float $lineTotal): TransactionItem
    {
        return TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => $lineTotal,
            'line_total' => $lineTotal,
        ]);
    }

    private function addPayment(Transaction $tx, float $baseEquivalent, string $method = 'Cash', float $rounding = 0): Payment
    {
        return Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'method' => $method,
            'amount' => $baseEquivalent,
            'currency_code' => 'USD',
            'base_equivalent' => $baseEquivalent,
            'rounding_adjustment' => $rounding,
        ]);
    }

    private function addSaleStockMovement(Transaction $tx, float $qty, float $runningAvgCost): StockMovement
    {
        return StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tx->business_id,
            'product_id' => (string) Str::uuid(),
            'type' => 'sale',
            'quantity_change' => -$qty,
            'running_avg_cost' => $runningAvgCost,
            'reference_id' => $tx->id,
            'user_id' => $tx->user_id,
        ]);
    }

    public function test_posting_is_skipped_when_the_business_has_no_go_live_date(): void
    {
        $businessId = $this->makeLiveBusiness('biz-1', goLive: null);
        $tx = $this->makeSale($businessId, 100, 0, 100);
        $this->addItem($tx, 100);
        $this->addPayment($tx, 100);

        $this->posting->postIfReady($tx);

        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    public function test_posting_is_skipped_for_a_sale_dated_before_the_go_live_date(): void
    {
        $businessId = $this->makeLiveBusiness('biz-1', goLive: '2026-06-15');
        $tx = $this->makeSale($businessId, 100, 0, 100, createdAt: '2026-06-01 10:00:00');
        $this->addItem($tx, 100);
        $this->addPayment($tx, 100);

        $this->posting->postIfReady($tx);

        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    public function test_layby_is_never_auto_posted(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, 100, 0, 100, status: 'layby');
        $this->addItem($tx, 100);
        $this->addPayment($tx, 20); // deposit only

        $this->posting->postIfReady($tx);

        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    public function test_a_refund_transactions_negative_amounts_reverse_the_books_directly(): void
    {
        $businessId = $this->makeLiveBusiness();

        $original = $this->makeSale($businessId, 100, 0, 100);
        $this->addItem($original, 100);
        $this->addPayment($original, 100);
        $this->posting->postIfReady($original);

        $refund = $this->makeSale($businessId, -100, 0, -100, status: 'refunded');
        $this->addItem($refund, -100);
        $this->addPayment($refund, -100);
        $this->posting->postIfReady($refund);

        $this->assertSame(0.0, $this->account($businessId, '1000')->balance());
        $this->assertSame(0.0, $this->account($businessId, '4000')->balance());
    }
}
