<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Customer;
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
    ): Transaction {
        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $businessId,
            'user_id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'discount_total' => 0,
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

    public function test_a_complete_cash_sale_posts_a_balanced_journal_including_cogs(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, subtotal: 100, tax: 15, total: 115);
        $this->addItem($tx, 100);
        $this->addPayment($tx, 115);
        $this->addSaleStockMovement($tx, qty: 2, runningAvgCost: 30); // COGS = 60

        $this->posting->postIfReady($tx);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);

        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');
        $tax = $this->account($businessId, '2030');
        $cogs = $this->account($businessId, '5000');
        $inventory = $this->account($businessId, '1200');

        $this->assertSame(115.0, $cash->balance());
        $this->assertSame(100.0, $revenue->balance());
        $this->assertSame(15.0, $tax->balance());
        $this->assertSame(60.0, $cogs->balance());
        $this->assertSame(-60.0, $inventory->balance()); // credited, stock leaving
    }

    public function test_a_credit_sale_debits_accounts_receivable_and_tags_the_customer(): void
    {
        $businessId = $this->makeLiveBusiness();
        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $businessId, 'name' => 'Jane Doe']);

        $tx = $this->makeSale($businessId, 50, 0, 50, status: 'credit_sale', customerId: $customerId);
        $this->addItem($tx, 50);
        $this->addPayment($tx, 50, method: 'credit');

        $this->posting->postIfReady($tx);

        $receivable = $this->account($businessId, '1100');
        $this->assertSame(50.0, $receivable->balance());

        $line = GeneralLedgerEntry::where('gl_account_id', $receivable->id)->first();
        $this->assertSame('customer', $line->party_type);
        $this->assertSame($customerId, $line->party_id);
    }

    public function test_cash_rounding_adjustment_posts_to_the_variance_account(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, 19.97, 0, 19.97);
        $this->addItem($tx, 19.97);
        // Till collected $20.00 cash for a $19.97 sale — +$0.03 rounding.
        $this->addPayment($tx, 20.00, rounding: 0.03);

        $this->posting->postIfReady($tx);

        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');
        $rounding = $this->account($businessId, '6060');

        $this->assertSame(20.00, $cash->balance());
        $this->assertSame(19.97, $revenue->balance());
        // Cash Rounding Variance is seeded under Expenses (debit-normal), so
        // crediting it for a rounding *gain* correctly shows as a negative
        // balance — "negative expense" — not a positive one.
        $this->assertSame(-0.03, $rounding->balance());
    }

    public function test_voiding_a_posted_sale_reverses_its_journal(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, 100, 0, 100);
        $this->addItem($tx, 100);
        $this->addPayment($tx, 100);
        $this->posting->postIfReady($tx);

        $cash = $this->account($businessId, '1000');
        $this->assertSame(100.0, $cash->balance());

        $tx->update(['status' => 'voided']);
        $this->posting->postIfReady($tx->fresh());

        $this->assertSame(0.0, $cash->fresh()->balance());
        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first();
        $this->assertSame('reversed', $journal->status);
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

    public function test_a_sale_dated_on_the_go_live_date_itself_does_post(): void
    {
        $businessId = $this->makeLiveBusiness('biz-1', goLive: '2026-06-01');
        $tx = $this->makeSale($businessId, 100, 0, 100, createdAt: '2026-06-01 08:00:00');
        $this->addItem($tx, 100);
        $this->addPayment($tx, 100);

        $this->posting->postIfReady($tx);

        $this->assertNotNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    public function test_posting_waits_for_items_and_payments_to_have_synced(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, 100, 0, 100);

        // Nothing else has synced yet.
        $this->posting->postIfReady($tx);
        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());

        $this->addItem($tx, 100);
        $this->posting->postIfReady($tx); // still no payment
        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());

        $this->addPayment($tx, 100);
        $this->posting->postIfReady($tx); // now ready
        $this->assertNotNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    public function test_posting_the_same_sale_twice_does_not_double_post(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, 100, 0, 100);
        $this->addItem($tx, 100);
        $this->addPayment($tx, 100);

        $this->posting->postIfReady($tx);
        $this->posting->postIfReady($tx);

        $this->assertSame(1, JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->count());
        $this->assertSame(100.0, $this->account($businessId, '1000')->balance());
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
