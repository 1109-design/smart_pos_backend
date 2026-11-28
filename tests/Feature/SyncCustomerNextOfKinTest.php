<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A customer's next of kin, captured on the till's customer form, is kept
 * when the till pushes the customer and handed to other tills on pull.
 */
class SyncCustomerNextOfKinTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId, string $who = 'owner'): string
    {
        $user = User::factory()->create(['email' => $tenantId.'-'.$who.'@example.com']);
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

    public function test_pushed_next_of_kin_is_stored_and_pulled_back(): void
    {
        $tenantId = 'tenant-next-of-kin';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $token = $this->actingDeviceToken($tenantId);
        $customerId = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'name' => 'Moyo Builders',
                        'phone' => '0771111111',
                        'next_of_kin_name' => 'Jane Moyo',
                        'next_of_kin_relationship' => 'Spouse',
                        'next_of_kin_phone' => '0772123456',
                        'next_of_kin_address' => '5 Main St, Hwange',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'accepted');

        $customer = Customer::findOrFail($customerId);
        $this->assertSame('Jane Moyo', $customer->next_of_kin_name);
        $this->assertSame('Spouse', $customer->next_of_kin_relationship);
        $this->assertSame('0772123456', $customer->next_of_kin_phone);
        $this->assertSame('5 Main St, Hwange', $customer->next_of_kin_address);

        // Another till: a device never gets its own pushes back.
        $otherTill = $this->actingDeviceToken($tenantId, 'second-till');
        $pulled = collect($this->withHeader('Authorization', 'Bearer '.$otherTill)
            ->getJson('/api/v1/sync/pull')
            ->assertOk()
            ->json('records'))
            ->firstWhere('record_uuid', $customerId);

        $this->assertNotNull($pulled);
        $this->assertSame('Jane Moyo', $pulled['payload']['next_of_kin_name']);
        $this->assertSame('5 Main St, Hwange', $pulled['payload']['next_of_kin_address']);
    }

    public function test_a_customer_push_without_next_of_kin_keeps_it(): void
    {
        $tenantId = 'tenant-next-of-kin-keep';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $token = $this->actingDeviceToken($tenantId);
        $customerId = (string) Str::uuid();
        Customer::create([
            'id' => $customerId,
            'business_id' => $tenantId,
            'name' => 'Moyo Builders',
            'next_of_kin_name' => 'Jane Moyo',
            'next_of_kin_phone' => '0772123456',
        ]);

        // e.g. a balance update from another screen: no next-of-kin keys.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'customers',
                    'uuid' => $customerId,
                    'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'name' => 'Moyo Builders Ltd'],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk();

        $customer = Customer::findOrFail($customerId);
        $this->assertSame('Moyo Builders Ltd', $customer->name);
        $this->assertSame('Jane Moyo', $customer->next_of_kin_name);
        $this->assertSame('0772123456', $customer->next_of_kin_phone);
    }
}
