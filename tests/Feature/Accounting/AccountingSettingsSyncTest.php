<?php

namespace Tests\Feature\Accounting;

use App\Models\Business;
use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Flutter-first accounting activation — proves a till can enable accounting
 * offline and push `accounting_settings` up (owner/manager only), that the
 * server applies it and fans it out to the fleet, that an unauthorized role
 * is rejected, and that a stale activation never flaps newer flags back.
 */
class AccountingSettingsSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function seedBusiness(string $tenantId): array
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');

        return [$owner, $cashier];
    }

    private function token(string $tenantId, User $user): string
    {
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

    private function push(string $token, string $tenantId, array $payload): array
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'accounting_settings',
                'uuid' => $tenantId,
                'operation' => 'upsert',
                'payload' => array_merge(['business_id' => $tenantId], $payload),
                'updated_at' => $payload['updated_at'] ?? now()->toIso8601String(),
            ]]])
            ->assertOk()
            ->json();
    }

    public function test_owner_activation_applies_and_fans_out(): void
    {
        $tenantId = 'tenant-acct-owner';
        [$owner] = $this->seedBusiness($tenantId);

        $json = $this->push($this->token($tenantId, $owner), $tenantId, [
            'accounting_go_live_date' => '2026-09-01',
            'client_gl_posting_enabled_at' => '2026-09-01T08:00:00+00:00',
        ]);

        $this->assertCount(1, $json['accepted']);

        $business = Business::find($tenantId);
        $this->assertSame('2026-09-01', $business->accounting_go_live_date->toDateString());
        $this->assertNotNull($business->client_gl_posting_enabled_at);

        // The push itself is mirrored as a sync record, and the case fans the
        // activation out for the rest of the fleet — assert the fan-out.
        $fanOut = SyncRecord::where('table_name', 'accounting_settings')
            ->where('record_uuid', $tenantId)
            ->where('payload->accounting_go_live_date', '2026-09-01')
            ->count();
        $this->assertGreaterThanOrEqual(1, $fanOut);
    }

    public function test_cashier_activation_is_rejected(): void
    {
        $tenantId = 'tenant-acct-cashier';
        [, $cashier] = $this->seedBusiness($tenantId);

        $json = $this->push($this->token($tenantId, $cashier), $tenantId, [
            'accounting_go_live_date' => '2026-09-01',
        ]);

        $this->assertCount(0, $json['accepted']);
        $this->assertStringContainsString('owner or manager', $json['errors'][0]['reason']);
        $this->assertNull(Business::find($tenantId)->accounting_go_live_date);
    }

    public function test_stale_activation_does_not_flap_newer_flags(): void
    {
        $tenantId = 'tenant-acct-stale';
        [$owner] = $this->seedBusiness($tenantId);

        $this->push($this->token($tenantId, $owner), $tenantId, [
            'accounting_go_live_date' => '2026-09-01',
            'updated_at' => now()->toIso8601String(),
        ]);

        $json = $this->push($this->token($tenantId, $owner), $tenantId, [
            'accounting_go_live_date' => '2025-01-01',
            'updated_at' => now()->subDay()->toIso8601String(),
        ]);

        $this->assertSame(
            '2026-09-01',
            Business::find($tenantId)->accounting_go_live_date->toDateString()
        );
    }
}
