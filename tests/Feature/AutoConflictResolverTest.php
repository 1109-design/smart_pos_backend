<?php

namespace Tests\Feature;

use App\Events\SyncTablesChanged;
use App\Models\Business;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the push pipeline auto-resolves conflicts that are provably safe
 * (identical content, equivalent unique-key duplicates) instead of parking
 * them for manual review — the two classes that made up 19 of the 20
 * real-world pending conflicts on the production business.
 */
class AutoConflictResolverTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);

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

    public function test_identical_version_push_auto_resolves_keep_server(): void
    {
        $tenantId = 'tenant-auto-identical';
        $token = $this->actingDeviceToken($tenantId);
        $uuid = (string) Str::uuid();

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'products',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => ['business_id' => $tenantId, 'name' => 'Widget', 'price' => 10],
            'source_updated_at' => now()->addHour(),
            'synced_at' => now()->addHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'products',
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'Widget', 'price' => 10],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('conflicts'));
        $this->assertCount(1, $response->json('auto_resolved'));

        $conflict = SyncConflict::first();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('accept_server_identical', $conflict->resolution_action);
    }

    public function test_differing_version_push_stays_manual(): void
    {
        $tenantId = 'tenant-auto-differ';
        $token = $this->actingDeviceToken($tenantId);
        $uuid = (string) Str::uuid();

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'stock_take_items',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => ['business_id' => $tenantId, 'counted_qty' => 12],
            'source_updated_at' => now()->addHour(),
            'synced_at' => now()->addHour(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'stock_take_items',
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'counted_qty' => 9],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertCount(1, $response->json('conflicts'));
        $this->assertSame([], $response->json('auto_resolved'));
        $this->assertSame('pending', SyncConflict::first()->status);
    }

    public function test_equivalent_unit_duplicate_auto_resolves(): void
    {
        $tenantId = 'tenant-auto-dupe';
        $token = $this->actingDeviceToken($tenantId);

        UnitOfMeasure::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'piece',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'piece', 'is_active' => true],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('conflicts'));
        $this->assertSame([], $response->json('errors'));
        $this->assertCount(1, $response->json('auto_resolved'));

        $conflict = SyncConflict::first();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('accept_server_duplicate', $conflict->resolution_action);
        // No second row created.
        $this->assertSame(1, UnitOfMeasure::where('business_id', $tenantId)->count());
    }

    public function test_differing_unit_duplicate_stays_manual(): void
    {
        $tenantId = 'tenant-auto-dupe-differ';
        $token = $this->actingDeviceToken($tenantId);

        UnitOfMeasure::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'piece',
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'piece', 'is_active' => false],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $response->assertOk();
        $this->assertSame([], $response->json('auto_resolved'));
        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame('pending', SyncConflict::first()->status);
    }

    public function test_accepted_push_dispatches_tables_changed_event(): void
    {
        $tenantId = 'tenant-realtime-dispatch';
        $token = $this->actingDeviceToken($tenantId);

        Event::fake([SyncTablesChanged::class]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'crate', 'is_active' => true],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        Event::assertDispatched(SyncTablesChanged::class, function ($event) use ($tenantId) {
            return $event->businessId === $tenantId && $event->tables === ['units_of_measure'];
        });
    }

    public function test_rejected_only_push_dispatches_no_event(): void
    {
        $tenantId = 'tenant-realtime-rejected';
        $token = $this->actingDeviceToken($tenantId);

        UnitOfMeasure::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'piece',
            'is_active' => true,
        ]);

        Event::fake([SyncTablesChanged::class]);

        // Differing duplicate → manual error, nothing accepted.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'units_of_measure',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'name' => 'piece', 'is_active' => false],
                'updated_at' => now()->toIso8601String(),
            ]]])->assertOk();

        Event::assertNotDispatched(SyncTablesChanged::class);
    }
}
