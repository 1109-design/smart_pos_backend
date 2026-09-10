<?php

namespace Tests\Feature;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master spec section 13 ("Idempotent Synchronization") mandatory test: a
 * device that pushes a batch, loses the response to a network failure, and
 * retries with the EXACT SAME records must never produce a duplicate sale,
 * duplicate stock movement, or duplicate journal entry — the server must
 * recognize the retried uuids and apply them exactly once.
 *
 * No prior test exercised this scenario end-to-end (transaction + item +
 * payment + stock movement, replayed as a single retried batch) even though
 * the underlying upsert-by-uuid + postIfReady()/existingJournal() guards were
 * already designed for it — this locks that behaviour in as a regression.
 */
class SyncPushIdempotencyRetryTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_retrying_an_identical_sale_batch_never_duplicates_the_transaction_stock_or_journal(): void
    {
        $tenantId = 'tenant-idempotent-retry';
        $token = $this->actingDeviceToken($tenantId);

        $txId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $productId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $paymentId = (string) Str::uuid();
        $movementId = (string) Str::uuid();

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Test Product',
            'price' => 40,
            'stock_quantity' => 10,
        ]);

        $updatedAt = now()->toIso8601String();

        // Stock is recomputed purely from the stock_movements ledger (see
        // SyncStockMovementTest), so an opening balance needs its own
        // movement — the same way a real device records take-on stock.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'stock_movements',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'product_id' => $productId,
                    'type' => 'adjustment',
                    'quantity_change' => 10,
                    'reason' => 'Opening balance',
                ],
                'updated_at' => $updatedAt,
            ]]])->assertOk();

        $records = [
            [
                'table' => 'transactions',
                'uuid' => $txId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'user_id' => $userId,
                    'subtotal' => 40,
                    'tax_total' => 0,
                    'discount_total' => 0,
                    'total' => 40,
                    'base_currency' => 'USD',
                    'status' => 'completed',
                    'created_at' => '2026-06-10T12:00:00Z',
                ],
                'updated_at' => $updatedAt,
            ],
            [
                'table' => 'transaction_items',
                'uuid' => $itemId,
                'operation' => 'upsert',
                'payload' => [
                    'transaction_id' => $txId,
                    'product_id' => $productId,
                    'product_name' => 'Test Product',
                    'quantity' => 1,
                    'unit_price' => 40,
                    'line_total' => 40,
                ],
                'updated_at' => $updatedAt,
            ],
            [
                'table' => 'stock_movements',
                'uuid' => $movementId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'product_id' => $productId,
                    'type' => 'sale',
                    'quantity_change' => -1,
                    'unit_cost' => 25,
                    'reference_id' => $txId,
                ],
                'updated_at' => $updatedAt,
            ],
            [
                'table' => 'payments',
                'uuid' => $paymentId,
                'operation' => 'upsert',
                'payload' => [
                    'transaction_id' => $txId,
                    'method' => 'Cash',
                    'amount' => 40,
                    'currency_code' => 'USD',
                    'base_equivalent' => 40,
                ],
                'updated_at' => $updatedAt,
            ],
        ];

        $push = fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => $records]);

        // First push: the till's "network failed before the response arrived"
        // attempt. From the server's perspective this succeeded.
        $first = $push();
        $first->assertOk();
        $this->assertCount(4, $first->json('accepted'));
        $this->assertCount(0, $first->json('errors'));

        // Sanity-check the sale posted correctly the first time.
        $this->assertSame(1, Transaction::where('id', $txId)->count());
        $this->assertSame(1, TransactionItem::where('id', $itemId)->count());
        $this->assertSame(1, Payment::where('id', $paymentId)->count());
        $this->assertSame(1, StockMovement::where('id', $movementId)->count());
        $this->assertEquals(9, Product::find($productId)->stock_quantity);

        $journal = JournalHeader::where('source_type', 'sale')->where('source_id', $txId)->first();
        $this->assertNotNull($journal);
        $this->assertSame('posted', $journal->status);

        // The till, having never received a response, retries the identical
        // batch verbatim (same uuids, same payload, same updated_at).
        $second = $push();
        $second->assertOk();
        $this->assertCount(0, $second->json('errors'), 'a pure retry must not be treated as a processing error');
        $this->assertCount(0, $second->json('conflicts'), 'a pure retry must not be treated as a version conflict');

        // Exactly one of everything — never two.
        $this->assertSame(1, Transaction::where('id', $txId)->count());
        $this->assertSame(1, TransactionItem::where('id', $itemId)->count());
        $this->assertSame(1, Payment::where('id', $paymentId)->count());
        $this->assertSame(1, StockMovement::where('id', $movementId)->count());
        $this->assertSame(1, JournalHeader::where('source_type', 'sale')->where('source_id', $txId)->count());

        // Stock must still reflect exactly ONE unit sold, not two.
        $this->assertEquals(9, Product::find($productId)->fresh()->stock_quantity);

        // The journal must still balance to the single sale, not double it.
        $cash = GlAccount::where('business_id', $tenantId)->where('code', '1000')->first();
        $revenue = GlAccount::where('business_id', $tenantId)->where('code', '4000')->first();
        $this->assertSame(40.0, $cash->balance());
        $this->assertSame(40.0, $revenue->balance());
    }
}
