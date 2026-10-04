<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TillCredentials;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Till passwords (user_credentials) and the owner's password policy
 * (password_policies) pushed from a till.
 */
class SyncTillPasswordsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function tokenFor(string $tenantId, string $role): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $this->user = User::factory()->create(['business_id' => $tenantId, 'email' => "{$tenantId}@example.com"]);
        $this->user->assignRole($role);

        $plain = $this->user->createToken('sync-test')->plainTextToken;
        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function push(string $token, string $table, string $uuid, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => $table,
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => $payload,
                'updated_at' => now()->toIso8601String(),
            ]]]);
    }

    public function test_a_till_password_change_is_stored_and_goes_out_to_other_tills(): void
    {
        $token = $this->tokenFor('tenant-pw-1', 'cashier');
        $hash = Hash::make('New-Pass-123');

        $this->push($token, 'user_credentials', $this->user->id, [
            'user_id' => $this->user->id,
            'business_id' => 'tenant-pw-1',
            'password_hash' => $hash,
            'must_change' => false,
            'password_changed_at' => now()->toIso8601String(),
            'history_json' => '[]',
        ])->assertOk()->assertJsonCount(1, 'accepted');

        $this->assertTrue(app(TillCredentials::class)->check($this->user, 'New-Pass-123'));
        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'user_credentials',
            'record_uuid' => $this->user->id,
        ]);
    }

    public function test_a_plain_password_is_refused(): void
    {
        $token = $this->tokenFor('tenant-pw-2', 'cashier');

        $this->push($token, 'user_credentials', $this->user->id, [
            'business_id' => 'tenant-pw-2',
            'password_hash' => 'New-Pass-123',
        ])->assertOk()->assertJsonCount(1, 'errors');

        $this->assertNull(DB::table('user_credentials')->where('user_id', $this->user->id)->first());
    }

    public function test_a_dart_labelled_bcrypt_hash_is_accepted_and_stored_as_2y(): void
    {
        $token = $this->tokenFor('tenant-pw-2a', 'cashier');
        // What the till's Dart bcrypt package produces: the same algorithm
        // as PHP's, labelled $2a$ instead of $2y$.
        $hash = preg_replace('/^\$2y\$/', '\$2a\$', Hash::make('New-Pass-123'));
        $old = preg_replace('/^\$2y\$/', '\$2b\$', Hash::make('Old-Pass-456'));

        $this->push($token, 'user_credentials', $this->user->id, [
            'user_id' => $this->user->id,
            'business_id' => 'tenant-pw-2a',
            'password_hash' => $hash,
            'must_change' => false,
            'password_changed_at' => now()->toIso8601String(),
            'history_json' => json_encode([$old]),
        ])->assertOk()->assertJsonCount(1, 'accepted');

        $row = DB::table('user_credentials')->where('user_id', $this->user->id)->first();
        $this->assertStringStartsWith('$2y$', $row->password_hash);
        $this->assertSame('$2y$'.substr($hash, 4), $row->password_hash);
        $this->assertSame(['$2y$'.substr($old, 4)], json_decode($row->history_json, true));
        $this->assertTrue(app(TillCredentials::class)->check($this->user, 'New-Pass-123'));
    }

    public function test_a_till_cannot_set_a_password_for_another_business(): void
    {
        $token = $this->tokenFor('tenant-pw-3', 'business_owner');
        Tenant::create(['id' => 'tenant-pw-other', 'business_name' => 'Other', 'owner_email' => 'o@example.com']);
        $victim = User::factory()->create(['business_id' => 'tenant-pw-other', 'email' => 'victim@example.com']);

        foreach (['tenant-pw-3', 'tenant-pw-other'] as $claimed) {
            $this->push($token, 'user_credentials', $victim->id, [
                'business_id' => $claimed,
                'password_hash' => Hash::make('Hijack-123'),
            ])->assertOk()->assertJsonCount(1, 'errors');
        }

        $this->assertNull(DB::table('user_credentials')->where('user_id', $victim->id)->first());
    }

    public function test_a_manager_cannot_change_the_password_policy(): void
    {
        $token = $this->tokenFor('tenant-pw-4', 'manager');
        $this->push($token, 'password_policies', 'tenant-pw-4', ['min_length' => 12])
            ->assertOk()->assertJsonCount(1, 'errors');
        $this->assertNull(DB::table('password_policies')->where('business_id', 'tenant-pw-4')->first());
    }

    public function test_the_owner_can_change_the_password_policy(): void
    {
        $ownerToken = $this->tokenFor('tenant-pw-5', 'business_owner');
        $this->push($ownerToken, 'password_policies', 'tenant-pw-5', [
            'business_id' => 'tenant-pw-5',
            'min_length' => 12,
            'require_symbol' => true,
            'expiry_days' => 0,
        ])->assertOk()->assertJsonCount(1, 'accepted');

        $policy = app(TillCredentials::class)->policy('tenant-pw-5');
        $this->assertSame(12, $policy['min_length']);
        $this->assertTrue($policy['require_symbol']);
        $this->assertSame(0, $policy['expiry_days']);
        // Unsent fields keep their defaults.
        $this->assertSame(5, $policy['max_attempts']);
    }

    public function test_a_synced_policy_cannot_go_below_a_sane_floor(): void
    {
        $ownerToken = $this->tokenFor('tenant-pw-6', 'business_owner');
        $this->push($ownerToken, 'password_policies', 'tenant-pw-6', [
            'business_id' => 'tenant-pw-6',
            'min_length' => 2,
            'max_attempts' => 0,
        ])->assertOk();

        $policy = app(TillCredentials::class)->policy('tenant-pw-6');
        $this->assertSame(6, $policy['min_length']);
        $this->assertSame(1, $policy['max_attempts']);
    }

    public function test_policy_violations_are_reported(): void
    {
        $credentials = app(TillCredentials::class);

        $this->assertSame([], $credentials->violations('none', 'Good-Pass-1', 'Tendai Moyo'));
        $this->assertContains('An uppercase letter', $credentials->violations('none', 'good-pass-1'));
        $this->assertContains('A number', $credentials->violations('none', 'Good-Pass'));
        $this->assertContains('At least 8 characters', $credentials->violations('none', 'Go-1'));
        $this->assertContains('Must not contain your name', $credentials->violations('none', 'Tendai-2026', 'Tendai Moyo'));
    }
}
