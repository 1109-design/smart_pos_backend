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

    /**
     * Regression for the tax-double-count bug: a tax-inclusive product's
     * `subtotal` (Flutter's CartItem.subtotalAfterDiscount) already has tax
     * embedded in it, so `total` equals `subtotal` exactly (nothing gets
     * added on top) — the old `subtotal - discount_total + surcharge_total`
     * revenue formula credited the full gross amount to Revenue AND the
     * same tax again to Tax Payable, overstating credits by the tax amount
     * on every such sale and leaving the journal stuck as an unposted
     * 'draft' that never reached general_ledger.
     */
    public function test_a_tax_inclusive_sale_posts_a_balanced_journal(): void
    {
        $businessId = $this->makeLiveBusiness();
        // $100 gross, 15% VAT already embedded: tax = 100 * 15/115 ≈ 13.04.
        $tx = $this->makeSale($businessId, subtotal: 100, tax: 13.04, total: 100);
        $this->addItem($tx, 100);
        $this->addPayment($tx, 100);

        $this->posting->postIfReady($tx);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);

        $revenue = $this->account($businessId, '4000');
        $tax = $this->account($businessId, '2030');

        $this->assertSame(86.96, $revenue->balance());
        $this->assertSame(13.04, $tax->balance());
    }

    /**
     * Regression: `subtotal` is already net of cart-item discounts
     * (Flutter's CartItem.subtotalAfterDiscount), so the old formula's
     * `- discount_total` subtracted the same discount a second time,
     * understating Revenue and unbalancing the journal.
     */
    public function test_a_discounted_sale_posts_a_balanced_journal(): void
    {
        $businessId = $this->makeLiveBusiness();
        // $100 line discounted by $10 -> subtotal 90 (net), 15% tax on top = 13.5, total 103.5.
        $tx = $this->makeSale($businessId, subtotal: 90, tax: 13.5, total: 103.5, discount: 10);
        $this->addItem($tx, 90);
        $this->addPayment($tx, 103.5);

        $this->posting->postIfReady($tx);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);

        $revenue = $this->account($businessId, '4000');
        $this->assertSame(90.0, $revenue->balance());
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

    private function addStockMovement(Transaction $tx, string $type, float $quantityChange, float $runningAvgCost): StockMovement
    {
        return StockMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tx->business_id,
            'product_id' => (string) Str::uuid(),
            'type' => $type,
            'quantity_change' => $quantityChange,
            'running_avg_cost' => $runningAvgCost,
            'reference_id' => $tx->id,
            'user_id' => $tx->user_id,
        ]);
    }

    private function makeExchange(string $businessId, float $total): Transaction
    {
        $tx = $this->makeSale($businessId, $total, 0, $total);
        $tx->forceFill(['exchange_of_transaction_id' => (string) Str::uuid()])->save();

        return $tx->fresh();
    }

    public function test_an_even_swap_exchange_posts_without_waiting_for_a_payment(): void
    {
        $businessId = $this->makeLiveBusiness();
        // Returned $40 kettle (cost 25) swapped for a $40 toaster (cost 35).
        $tx = $this->makeExchange($businessId, 0);
        $this->addItem($tx, -40);
        $this->addItem($tx, 40);
        $this->addStockMovement($tx, 'return', 1, 25);
        $this->addStockMovement($tx, 'sale', -1, 35);

        $this->posting->postIfReady($tx);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);
        $this->assertSame(0.0, $this->account($businessId, '1000')->balance());
        $this->assertSame(10.0, $this->account($businessId, '5000')->balance());
        $this->assertSame(-10.0, $this->account($businessId, '1200')->balance());
    }

    public function test_a_same_cost_like_for_like_exchange_leaves_no_empty_draft_behind(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeExchange($businessId, 0);
        $this->addItem($tx, -40);
        $this->addItem($tx, 40);
        $this->addStockMovement($tx, 'return', 1, 25);
        $this->addStockMovement($tx, 'sale', -1, 25);

        $this->posting->postIfReady($tx);

        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    public function test_an_exchange_with_a_payout_credits_cash_and_reverses_the_returned_cogs(): void
    {
        $businessId = $this->makeLiveBusiness();
        // Cash in the till from the original $40 sale.
        $original = $this->makeSale($businessId, 40, 0, 40);
        $this->addItem($original, 40);
        $this->addPayment($original, 40);
        $this->posting->postIfReady($original);

        // Returned $40 kettle (cost 25) for a $10 mug (cost 4): $30 paid back.
        $tx = $this->makeExchange($businessId, -30);
        $this->addItem($tx, -40);
        $this->addItem($tx, 10);
        $this->addPayment($tx, -30);
        $this->addStockMovement($tx, 'return', 1, 25);
        $this->addStockMovement($tx, 'sale', -1, 4);

        $this->posting->postIfReady($tx);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first();
        $this->assertSame('posted', $journal->status);
        $this->assertSame(10.0, $this->account($businessId, '1000')->balance());
        $this->assertSame(10.0, $this->account($businessId, '4000')->balance());
        // Net stock value back in: +25 kettle, -4 mug.
        $this->assertSame(21.0, $this->account($businessId, '1200')->balance());
        $this->assertSame(-21.0, $this->account($businessId, '5000')->balance());
    }

    public function test_a_zero_total_plain_sale_still_waits_for_its_payment(): void
    {
        $businessId = $this->makeLiveBusiness();
        $tx = $this->makeSale($businessId, 0, 0, 0);
        $this->addItem($tx, 0);

        $this->posting->postIfReady($tx);

        $this->assertNull(JournalHeader::where('source_type', 'sale')->where('source_id', $tx->id)->first());
    }

    /**
     * The till writes a refund's payout legs as positive amounts on the
     * negative-total reversal row; taken at face value they debited Cash
     * and left the journal unbalanced as a stuck draft.
     */
    public function test_a_refund_with_positive_payout_legs_as_sent_by_the_till_still_credits_cash(): void
    {
        $businessId = $this->makeLiveBusiness();

        $original = $this->makeSale($businessId, 100, 0, 100);
        $this->addItem($original, 100);
        $this->addPayment($original, 100);
        $this->posting->postIfReady($original);

        $refund = $this->makeSale($businessId, -40, 0, -40, status: 'refunded');
        $this->addItem($refund, -40);
        $this->addPayment($refund, 40);
        $this->addStockMovement($refund, 'return', 1, 25);
        $this->posting->postIfReady($refund);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $refund->id)->first();
        $this->assertSame('posted', $journal->status);
        $this->assertSame(60.0, $this->account($businessId, '1000')->balance());
        $this->assertSame(60.0, $this->account($businessId, '4000')->balance());
        // The returned unit's cost goes back into stock.
        $this->assertSame(25.0, $this->account($businessId, '1200')->balance());
    }
}
