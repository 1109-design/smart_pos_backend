<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockOversell;
use App\Models\SyncConflict;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncHealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncHealthReportTest extends TestCase
{
    use RefreshDatabase;

    private function tenantToken(string $tenantId, string $deviceName): array
    {
        Tenant::firstOrCreate(
            ['id' => $tenantId],
            ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']
        );

        $user = User::factory()->create();
        $plain = $user->createToken($deviceName)->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        $device = Device::create([
            'tenant_id' => $tenantId,
            'name' => $deviceName,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return [$plain, $device];
    }

    public function test_a_healthy_device_with_no_conflicts_or_oversells_reports_zeros(): void
    {
        $tenantId = 'tenant-health-1';
        [$token, $device] = $this->tenantToken($tenantId, 'Till 01');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/health');

        $response->assertOk();
        $response->assertJsonFragment([
            'device_id' => $device->id,
            'device_name' => 'Till 01',
            'pending' => 0,
            'failed' => 0,
        ]);

        $this->artisan('sync:health', ['business_id' => $tenantId])
            ->expectsTable(
                ['Device', 'Pending', 'Failed', 'Last Sync'],
                [['Till 01', 0, 0, 'never']]
            )
            ->assertExitCode(0);
    }

    public function test_a_pending_conflict_and_unresolved_oversell_show_up_in_both_command_and_endpoint(): void
    {
        $tenantId = 'tenant-health-2';
        [$token, $device] = $this->tenantToken($tenantId, 'Till 02');

        SyncConflict::create([
            'business_id' => $tenantId,
            'table_name' => 'categories',
            'record_uuid' => (string) Str::uuid(),
            'device_id' => $device->id,
            'reason' => 'Manual review required',
            'conflict_type' => 'version_conflict',
            'local_payload' => ['name' => 'Stuck'],
            'server_payload' => ['name' => 'Server'],
            'status' => 'pending',
        ]);

        StockOversell::create([
            'business_id' => $tenantId,
            'product_id' => (string) Str::uuid(),
            'location_id' => null,
            'computed_quantity' => -3,
            'shortfall' => 3,
            'detected_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/health');

        $response->assertOk();
        $response->assertJsonFragment([
            'device_id' => $device->id,
            'device_name' => 'Till 02',
            'pending' => 0,
            'failed' => 1,
            'unresolved_oversells_business_wide' => 1,
        ]);

        $this->artisan('sync:health', ['business_id' => $tenantId])
            ->expectsTable(
                ['Device', 'Pending', 'Failed', 'Last Sync'],
                [['Till 02', 0, 1, 'never']]
            )
            ->assertExitCode(0);
    }

    public function test_cross_tenant_isolation(): void
    {
        $tenantA = 'tenant-health-a';
        $tenantB = 'tenant-health-b';
        [$tokenA, $deviceA] = $this->tenantToken($tenantA, 'Till A');
        [, $deviceB] = $this->tenantToken($tenantB, 'Till B');

        SyncConflict::create([
            'business_id' => $tenantB,
            'table_name' => 'categories',
            'record_uuid' => (string) Str::uuid(),
            'device_id' => $deviceB->id,
            'reason' => 'Business B only',
            'conflict_type' => 'version_conflict',
            'local_payload' => ['name' => 'B'],
            'server_payload' => ['name' => 'B-server'],
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/v1/sync/health');

        $response->assertOk();
        $devices = collect($response->json('devices'));
        $this->assertCount(1, $devices);
        $this->assertSame($deviceA->id, $devices->first()['device_id']);
        $this->assertSame(0, $devices->first()['failed']);

        $this->artisan('sync:health', ['business_id' => $tenantB])
            ->expectsTable(
                ['Device', 'Pending', 'Failed', 'Last Sync'],
                [['Till B', 0, 1, 'never']]
            )
            ->assertExitCode(0);
    }

    public function test_integrity_section_reports_no_mismatch_when_changelog_and_live_table_agree(): void
    {
        $tenantId = 'tenant-health-integrity';
        [$token] = $this->tenantToken($tenantId, 'Till 03');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/health');

        $response->assertOk();
        $integrity = collect($response->json('integrity'));
        $this->assertTrue($integrity->every(fn ($row) => ($row['mismatch'] ?? false) === false));
    }

    public function test_flags_products_whose_total_disagrees_with_their_locations(): void
    {
        $tenantId = 'tenant-health-stock';
        $this->tenantToken($tenantId, 'Till 01');
        $locationId = (string) Str::uuid();
        Location::create(['id' => $locationId, 'business_id' => $tenantId, 'name' => 'Main', 'type' => 'shop', 'is_active' => true]);

        $ok = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Balanced', 'price' => 1, 'stock_quantity' => 10, 'track_stock' => true]);
        ProductStock::create(['id' => $ok->id.'|'.$locationId, 'product_id' => $ok->id, 'location_id' => $locationId, 'quantity' => 10]);

        $bad = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Hinge', 'price' => 1, 'stock_quantity' => 94, 'track_stock' => true]);
        ProductStock::create(['id' => $bad->id.'|'.$locationId, 'product_id' => $bad->id, 'location_id' => $locationId, 'quantity' => 46]);

        $mismatches = app(SyncHealthReport::class)->stockMismatchesFor($tenantId);

        $this->assertCount(1, $mismatches);
        $this->assertSame('Hinge', $mismatches[0]['name']);
        $this->assertEquals(94, $mismatches[0]['total']);
        $this->assertEquals(46, $mismatches[0]['locations_sum']);
    }
}
