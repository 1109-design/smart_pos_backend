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
 * Distinct from SyncPushIdempotencyRetryTest (which proves ONE retry after a
 * lost response never duplicates a sale). This test proves the same
 * property holds under repeated blind retries — 1, 2, 5 and 10 submissions
 * of the identical batch, as a client that keeps assuming failure (e.g.
 * across several app restarts) would actually do — and additionally proves
 * that every one of those retried submissions is still reported back to the
 * client as `accepted`, never as an `error`. A client that sees its retried
 * records show up under `errors` would reasonably conclude the sale never
 * went through and either retry forever or alarm the cashier, even though
 * the business transaction is in fact already correctly recorded exactly
 * once server-side.
 */
class SyncDuplicatePushIdempotencyTest extends TestCase
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

    public function test_a_sale_batch_retried_ten_times_still_converges_to_exactly_one_business_effect(): void
    {
        $tenantId = 'tenant-idempotent-retry-x10';
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
            'name' => 'Retry Product',
            'price' => 40,
            'stock_quantity' => 10,
        ]);

        $updatedAt = now()->toIso8601String();

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
                    'product_name' => 'Retry Product',
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

        // Submissions 1, 2, 5 and 10: a client that keeps assuming its
        // request failed (lost response, timeout, app killed before the
        // ack was processed, restarted, retried again) and blindly resends
        // the exact same batch — uuids, payload and updated_at unchanged.
        $submissionCountsToCheckAt = [1, 2, 5, 10];
        $response = null;

        for ($i = 1; $i <= 10; $i++) {
            $response = $push();
            $response->assertOk();

            if (in_array($i, $submissionCountsToCheckAt, true)) {
                // Every submission — including retries 2 through 10 — must
                // be reported as accepted, never as an error or a version
                // conflict. A client seeing its retried records under
                // `errors` has no way to know the sale already succeeded.
                $this->assertCount(
                    4,
                    $response->json('accepted'),
                    "submission #{$i}: all 4 records should be reported accepted"
                );
                $this->assertCount(0, $response->json('errors'), "submission #{$i}: a pure retry must never be an error");
                $this->assertCount(0, $response->json('conflicts'), "submission #{$i}: a pure retry must never be a version conflict");

                // ...yet the business effect never multiplies beyond one.
                $this->assertSame(1, Transaction::where('id', $txId)->count(), "submission #{$i}: exactly one transaction");
                $this->assertSame(1, TransactionItem::where('id', $itemId)->count(), "submission #{$i}: exactly one transaction item");
                $this->assertSame(1, Payment::where('id', $paymentId)->count(), "submission #{$i}: exactly one payment");
                $this->assertSame(1, StockMovement::where('id', $movementId)->count(), "submission #{$i}: exactly one stock movement");
                $this->assertEquals(9, Product::find($productId)->fresh()->stock_quantity, "submission #{$i}: stock reflects exactly one unit sold");
                $this->assertSame(
                    1,
                    JournalHeader::where('source_type', 'sale')->where('source_id', $txId)->count(),
                    "submission #{$i}: exactly one journal posting"
                );
            }
        }

        // The journal itself must still balance to a single $40 sale after
        // ten repeated submissions, not ten times that.
        $cash = GlAccount::where('business_id', $tenantId)->where('code', '1000')->first();
        $revenue = GlAccount::where('business_id', $tenantId)->where('code', '4000')->first();
        $this->assertSame(40.0, $cash->balance());
        $this->assertSame(40.0, $revenue->balance());
    }
}
