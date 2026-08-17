<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for a plaintext-PIN storage bug: the device is now
 * expected to bcrypt-hash PINs itself before they ever reach a sync
 * payload, but SyncProcessor::syncUser() must still defend against an
 * older app build (or any future write path) sending one in plain text —
 * that must never land in the users table unhashed.
 */
class SyncUserPinHashingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_a_plaintext_pin_is_hashed_before_being_stored(): void
    {
        $tenantId = 'tenant-pin-plaintext';
        $token = $this->actingDeviceToken($tenantId);
        $newUserId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'users',
                    'uuid' => $newUserId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Cashier One',
                        'email' => null,
                        'pin_hash' => '1234',
                        'role' => 'cashier',
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $stored = User::findOrFail($newUserId)->pin_hash;
        $this->assertNotSame('1234', $stored);
        $this->assertTrue(Hash::isHashed($stored));
        $this->assertTrue(Hash::check('1234', $stored));
    }

    public function test_an_already_hashed_pin_from_the_device_passes_through_unchanged(): void
    {
        $tenantId = 'tenant-pin-prehashed';
        $token = $this->actingDeviceToken($tenantId);
        $newUserId = (string) Str::uuid();
        // A real bcrypt hash, standing in for one already produced on-device
        // by Dart's bcrypt package (BCrypt.hashpw, which defaults to $2a$ —
        // but any valid bcrypt variant must pass Hash::isHashed()).
        $clientHashed = Hash::make('1234');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'users',
                    'uuid' => $newUserId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Cashier Two',
                        'email' => null,
                        'pin_hash' => $clientHashed,
                        'role' => 'cashier',
                        'is_active' => true,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $this->assertSame($clientHashed, User::findOrFail($newUserId)->pin_hash);
    }
}
