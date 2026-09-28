<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP multi-currency basis: tender amount/rate/base_equivalent pushed from
 * a device must survive the sync round-trip intact so the server-side
 * journal (which posts base) agrees with the device.
 */
class SyncSupplierPaymentCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_currency_basis_round_trips(): void
    {
        $tenantId = 'tenant-supplier-payment-fx';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'FX Supplies']);
        $manager = User::factory()->create(['email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $plain = $manager->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];
        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Manager Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);
        $uuid = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'supplier_payments',
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'supplier_id' => $supplier->id,
                        'amount' => 100,
                        'currency_code' => 'ZWG',
                        'exchange_rate_used' => 0.05,
                        'base_equivalent' => 5,
                        'payment_date' => now()->toDateString(),
                        'method' => 'bank_transfer',
                        'recorded_by_user_id' => $manager->id,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $payment = SupplierPayment::find($uuid);
        $this->assertNotNull($payment);
        $this->assertSame('ZWG', $payment->currency_code);
        $this->assertEquals(0.05, (float) $payment->exchange_rate_used);
        $this->assertEquals(5, (float) $payment->base_equivalent);
    }
}
