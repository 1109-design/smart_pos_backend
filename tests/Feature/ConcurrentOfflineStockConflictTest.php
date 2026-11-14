<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\SyncConflict;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Realtime/offline-first architecture audit (2026-09-17), scenario from
 * master spec section 9: Device A and Device B both go offline with the
 * same product at 100 units, A sells 20, B sells 30, then both reconnect
 * and push independently. Naive last-write-wins on `product_stock` would
 * leave the quantity at whichever device's full-row snapshot pushed last
 * (80 or 70) — silently wrong either way.
 *
 * What this proves the architecture actually does instead: each sale also
 * pushes an insert-only `stock_movements` ledger row (never conflicts —
 * distinct UUID per device), and SyncProcessor::recomputeLocationStock()
 * unconditionally recalculates product_stock.quantity as SUM(movements)
 * every time one lands, stamped with the server's own `now()` as
 * source_updated_at. Applying a later-arriving device's product_stock
 * snapshot recomputes quantity from the ledger again, so the final number
 * is ledger-derived (50), never a stale snapshot.
 */
class ConcurrentOfflineStockConflictTest extends TestCase
{
    use RefreshDatabase;

    private function deviceToken(string $tenantId, string $deviceIdentifier): string
    {
        $user = User::where('email', 'concurrent-stock-owner@example.com')->first()
            ?? User::factory()->create([
                'id' => '88888888-8888-4888-8888-888888888888',
                'email' => 'concurrent-stock-owner@example.com',
            ]);

        $plain = $user->createToken('sync-test-'.$deviceIdentifier)->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Device '.$deviceIdentifier,
            'device_identifier' => $deviceIdentifier,
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_two_devices_selling_offline_from_the_same_starting_stock_reconcile_via_the_ledger_not_last_write_wins(): void
    {
        $tenantId = 'tenant-concurrent-stock';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $tokenA = $this->deviceToken($tenantId, 'aaaaaaaa-0000-4000-8000-aaaaaaaaaaaa');
        $tokenB = $this->deviceToken($tenantId, 'bbbbbbbb-0000-4000-8000-bbbbbbbbbbbb');

        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Shared Widget',
            'price' => 10,
            'stock_quantity' => 100,
        ]);

        StockMovement::create([
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 100,
        ]);

        $canonicalStockId = "{$productId}|{$locationId}";

        // Both devices' local clocks are stamped well before "now" — they
        // were offline when they made these edits, exactly like a real
        // device's queued sync_records would be.
        $aOfflineClock = now()->subMinutes(20)->toIso8601String();
        $bOfflineClock = now()->subMinutes(15)->toIso8601String();

        // Device A comes online first: pushes its own locally-computed
        // product_stock snapshot (100 - 20 = 80) followed by the sale's
        // ledger movement — the same order CostService.adjustStock()
        // enqueues them in on the client.
        $responseA = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/sync/push', [
                'records' => [
                    [
                        'table' => 'product_stock',
                        'uuid' => $canonicalStockId,
                        'operation' => 'upsert',
                        'payload' => [
                            'product_id' => $productId,
                            'location_id' => $locationId,
                            'quantity' => 80,
                            'reserved_quantity' => 0,
                            'updated_at' => $aOfflineClock,
                        ],
                        'updated_at' => $aOfflineClock,
                    ],
                    [
                        'table' => 'stock_movements',
                        'uuid' => (string) Str::uuid(),
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'location_id' => $locationId,
                            'product_id' => $productId,
                            'type' => 'sale',
                            'quantity_change' => -20,
                            'updated_at' => $aOfflineClock,
                        ],
                        'updated_at' => $aOfflineClock,
                    ],
                ],
            ]);
        $responseA->assertOk();

        // Ledger-derived total after A alone: 100 - 20 = 80.
        $this->assertDatabaseHas('product_stock', [
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity' => 80,
        ]);

        // Device B comes online second, having independently sold 30 units
        // from the same starting 100 while it was offline — its own
        // product_stock snapshot (100 - 30 = 70) is stale the moment it
        // arrives, since the server has already moved on.
        $responseB = $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/sync/push', [
                'records' => [
                    [
                        'table' => 'product_stock',
                        'uuid' => $canonicalStockId,
                        'operation' => 'upsert',
                        'payload' => [
                            'product_id' => $productId,
                            'location_id' => $locationId,
                            'quantity' => 70,
                            'reserved_quantity' => 0,
                            'updated_at' => $bOfflineClock,
                        ],
                        'updated_at' => $bOfflineClock,
                    ],
                    [
                        'table' => 'stock_movements',
                        'uuid' => (string) Str::uuid(),
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'location_id' => $locationId,
                            'product_id' => $productId,
                            'type' => 'sale',
                            'quantity_change' => -30,
                            'updated_at' => $bOfflineClock,
                        ],
                        'updated_at' => $bOfflineClock,
                    ],
                ],
            ]);
        $responseB->assertOk();

        // The correct, ledger-derived result: 100 - 20 - 30 = 50 — neither
        // device's own stale snapshot (80 or 70) ever won.
        $stock = ProductStock::where('product_id', $productId)->where('location_id', $locationId)->first();
        $this->assertNotNull($stock);
        $this->assertEqualsWithDelta(50.0, (float) $stock->quantity, 0.0001);

        $this->assertEqualsWithDelta(
            50.0,
            (float) StockMovement::where('product_id', $productId)->where('location_id', $locationId)->sum('quantity_change'),
            0.0001
        );

        // Device B's push is newer than A's, the only other device push, so
        // it isn't a conflict: the server's recompute in between is measured
        // out, and applying B's snapshot still leaves quantity ledger-derived.
        $this->assertSame(0, SyncConflict::where('business_id', $tenantId)
            ->where('table_name', 'product_stock')
            ->count());
    }

    /**
     * A device that releases reserved stock and, in the same push, records
     * movements on that product (e.g. handing the freed stock to Pending
     * Book orders) must not lose the release to the recompute those
     * movements trigger — reserved_quantity is set only by devices.
     */
    public function test_reserved_release_is_not_lost_to_a_recompute_from_an_earlier_push(): void
    {
        $tenantId = 'tenant-reserved-release';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $token = $this->deviceToken($tenantId, 'cccccccc-0000-4000-8000-cccccccccccc');

        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        $stockId = "{$productId}|{$locationId}";

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Tiles',
            'price' => 10,
            'stock_quantity' => 54,
        ]);

        StockMovement::create([
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => 54,
        ]);

        $holdClock = now()->subMinutes(30)->toIso8601String();
        $releaseClock = now()->subMinutes(5)->toIso8601String();

        // The hold, pushed long ago.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_stock',
                    'uuid' => $stockId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 54,
                        'reserved_quantity' => 109,
                        'updated_at' => $holdClock,
                    ],
                    'updated_at' => $holdClock,
                ]],
            ])->assertOk();

        // An allocation movement lands first (an earlier batch of the same
        // sync), so the server's recompute is now newer than the release.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_movements',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $locationId,
                        'product_id' => $productId,
                        'type' => 'order_allocation',
                        'quantity_change' => -26,
                        'updated_at' => $releaseClock,
                    ],
                    'updated_at' => $releaseClock,
                ]],
            ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_stock',
                    'uuid' => $stockId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 54,
                        'reserved_quantity' => 0,
                        'updated_at' => $releaseClock,
                    ],
                    'updated_at' => $releaseClock,
                ]],
            ])->assertOk();

        $stock = ProductStock::where('product_id', $productId)->where('location_id', $locationId)->first();
        $this->assertEqualsWithDelta(0.0, (float) $stock->reserved_quantity, 0.0001);
        $this->assertEqualsWithDelta(28.0, (float) $stock->quantity, 0.0001);
        $this->assertSame(0, SyncConflict::where('business_id', $tenantId)->count());
    }

    /**
     * A product_stock push older than another device's push still loses,
     * even when a recompute sits on top of both.
     */
    public function test_push_older_than_another_devices_push_still_loses_behind_a_recompute(): void
    {
        $tenantId = 'tenant-reserved-stale';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $tokenA = $this->deviceToken($tenantId, 'dddddddd-0000-4000-8000-dddddddddddd');
        $tokenB = $this->deviceToken($tenantId, 'eeeeeeee-0000-4000-8000-eeeeeeeeeeee');

        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        $stockId = "{$productId}|{$locationId}";

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Widget',
            'price' => 10,
            'stock_quantity' => 10,
        ]);

        $earlierClock = now()->subMinutes(20)->toIso8601String();
        $laterClock = now()->subMinutes(10)->toIso8601String();

        // A reserves 4 (later), then a movement triggers a recompute.
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/sync/push', [
                'records' => [
                    [
                        'table' => 'product_stock',
                        'uuid' => $stockId,
                        'operation' => 'upsert',
                        'payload' => [
                            'product_id' => $productId,
                            'location_id' => $locationId,
                            'quantity' => 10,
                            'reserved_quantity' => 4,
                            'updated_at' => $laterClock,
                        ],
                        'updated_at' => $laterClock,
                    ],
                    [
                        'table' => 'stock_movements',
                        'uuid' => (string) Str::uuid(),
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'location_id' => $locationId,
                            'product_id' => $productId,
                            'type' => 'opening_stock',
                            'quantity_change' => 10,
                            'updated_at' => $laterClock,
                        ],
                        'updated_at' => $laterClock,
                    ],
                ],
            ])->assertOk();

        // B's older snapshot (reserved 0) must not overwrite A's hold.
        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_stock',
                    'uuid' => $stockId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 10,
                        'reserved_quantity' => 0,
                        'updated_at' => $earlierClock,
                    ],
                    'updated_at' => $earlierClock,
                ]],
            ])->assertOk();

        $stock = ProductStock::where('product_id', $productId)->where('location_id', $locationId)->first();
        $this->assertEqualsWithDelta(4.0, (float) $stock->reserved_quantity, 0.0001);
        $this->assertDatabaseHas('sync_conflicts', [
            'table_name' => 'product_stock',
            'record_uuid' => $stockId,
            'conflict_type' => 'version_conflict',
            'status' => 'resolved',
            'resolution_action' => 'ledger_recompute_authoritative',
        ]);
    }

    /**
     * Contrast case: a genuine device-vs-device edit on a product_stock
     * field the ledger never touches (low_stock_threshold) must still stay
     * a manual, pending conflict — isLedgerRecomputeSupersededConflict()
     * only fires when the *winning* record was itself system-generated by
     * a recompute (device_id null), never for one real device's edit
     * racing another's.
     */
    public function test_two_devices_editing_the_same_low_stock_threshold_still_requires_manual_review(): void
    {
        $tenantId = 'tenant-concurrent-stock-threshold';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $tokenA = $this->deviceToken($tenantId, 'aaaaaaaa-1111-4000-8000-aaaaaaaaaaaa');
        $tokenB = $this->deviceToken($tenantId, 'bbbbbbbb-1111-4000-8000-bbbbbbbbbbbb');

        $productId = (string) Str::uuid();
        $locationId = (string) Str::uuid();
        $canonicalStockId = "{$productId}|{$locationId}";

        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Shared Widget',
            'price' => 10,
            'stock_quantity' => 100,
        ]);

        $earlierClock = now()->subMinutes(20)->toIso8601String();
        $laterClock = now()->subMinutes(15)->toIso8601String();

        // Device A sets the threshold first, with the later timestamp — a
        // real device edit, so this SyncRecord carries a real device_id,
        // not null.
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_stock',
                    'uuid' => $canonicalStockId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 100,
                        'low_stock_threshold' => 10,
                        'updated_at' => $laterClock,
                    ],
                    'updated_at' => $laterClock,
                ]],
            ])->assertOk();

        // Device B independently set a different threshold, but with an
        // older timestamp than what the server now has — a genuine race
        // between two real edits, not a ledger recompute, so this must
        // stay a pending, manually-reviewed conflict.
        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'product_stock',
                    'uuid' => $canonicalStockId,
                    'operation' => 'upsert',
                    'payload' => [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 100,
                        'low_stock_threshold' => 5,
                        'updated_at' => $earlierClock,
                    ],
                    'updated_at' => $earlierClock,
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('sync_conflicts', [
            'table_name' => 'product_stock',
            'record_uuid' => $canonicalStockId,
            'conflict_type' => 'version_conflict',
            'status' => 'pending',
        ]);
    }
}
