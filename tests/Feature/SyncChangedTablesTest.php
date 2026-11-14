<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /sync/changed-tables tells a device which tables have records past
 * its own pull position, so it pulls only those instead of every table
 * every cycle.
 */
class SyncChangedTablesTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-changed-tables';

    /**
     * @return array{0: Device, 1: string}
     */
    private function actingDevice(): array
    {
        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => $this->tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $this->tenantId, 'email' => $this->tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;

        $device = Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return [$device, $plain];
    }

    private function record(string $table, Carbon $syncedAt, ?int $deviceId = null): SyncRecord
    {
        return SyncRecord::create([
            'business_id' => $this->tenantId,
            'table_name' => $table,
            'record_uuid' => (string) Str::uuid(),
            'operation' => 'upsert',
            'payload' => ['name' => 'x'],
            'source_updated_at' => $syncedAt,
            'synced_at' => $syncedAt,
            'device_id' => $deviceId,
        ]);
    }

    private function changed(string $token, array $cursors): array
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/changed-tables', ['cursors' => $cursors]);

        $response->assertOk();

        return collect($response->json('tables'))->sort()->values()->all();
    }

    public function test_lists_only_tables_with_records_past_the_device_cursor(): void
    {
        [, $token] = $this->actingDevice();
        $now = now()->startOfSecond();

        $this->record('products', $now->copy()->subMinutes(5));
        $this->record('customers', $now->copy()->subMinutes(30));

        $tables = $this->changed($token, [
            'products' => ['since' => $now->copy()->subMinutes(10)->toIso8601String(), 'after_id' => null],
            'customers' => ['since' => $now->copy()->subMinutes(20)->toIso8601String(), 'after_id' => null],
            'suppliers' => ['since' => $now->copy()->subMinutes(20)->toIso8601String(), 'after_id' => null],
        ]);

        $this->assertSame(['products'], $tables);
    }

    public function test_a_record_tied_on_since_counts_only_past_after_id(): void
    {
        [, $token] = $this->actingDevice();
        $at = now()->startOfSecond()->subMinutes(5);

        $first = $this->record('products', $at);
        $second = $this->record('products', $at);

        $this->assertSame(['products'], $this->changed($token, [
            'products' => ['since' => $at->toIso8601String(), 'after_id' => $first->id],
        ]));

        $this->assertSame([], $this->changed($token, [
            'products' => ['since' => $at->toIso8601String(), 'after_id' => $second->id],
        ]));
    }

    public function test_ignores_the_devices_own_records(): void
    {
        [$device, $token] = $this->actingDevice();
        $now = now()->startOfSecond();

        $this->record('products', $now->copy()->subMinutes(5), $device->id);

        $this->assertSame([], $this->changed($token, [
            'products' => ['since' => $now->copy()->subMinutes(10)->toIso8601String(), 'after_id' => null],
        ]));
    }

    public function test_a_never_pulled_table_is_listed_when_it_has_recent_records(): void
    {
        [, $token] = $this->actingDevice();

        $this->record('tills', now()->startOfSecond()->subHour());

        $this->assertSame(['tills'], $this->changed($token, [
            'tills' => ['since' => null, 'after_id' => null],
            'products' => ['since' => null, 'after_id' => null],
        ]));
    }

    public function test_marks_the_device_as_seen(): void
    {
        [$device, $token] = $this->actingDevice();

        $this->changed($token, []);

        $this->assertNotNull($device->fresh()->last_seen_at);
    }
}
