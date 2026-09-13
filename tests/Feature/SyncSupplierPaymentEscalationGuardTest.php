<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security audit follow-up, same class and same fix shape as
 * SyncTriggersSalaryPostingTest's 'salary_payments' guard: a fabricated
 * supplier_payments row is the accounts-payable version of the same fraud
 * — it reduces what the business shows as owing to a real supplier, with a
 * real Dr Accounts Payable / Cr Cash-or-Bank journal one push away from
 * posting. 'salary_payments' had already been fixed for this; its sibling
 * table 'supplier_payments' had no equivalent guard.
 */
class SyncSupplierPaymentEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cashier_cannot_record_a_supplier_payment(): void
    {
        $tenantId = 'tenant-supplier-payment-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $cashier = User::factory()->create(['email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $plain = $cashier->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];
        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Cashier Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'supplier_payments',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'supplier_id' => $supplier->id,
                        'amount' => 50000,
                        'currency_code' => 'USD',
                        'payment_date' => now()->toDateString(),
                        'method' => 'cash',
                        'recorded_by_user_id' => $cashier->id,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'supplier_payments: recording a payment requires owner or manager access.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame(0, SupplierPayment::where('business_id', $tenantId)->count());
    }

    public function test_a_manager_can_legitimately_record_a_supplier_payment(): void
    {
        $tenantId = 'tenant-supplier-payment-legit';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
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

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'supplier_payments',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'supplier_id' => $supplier->id,
                        'amount' => 50,
                        'currency_code' => 'USD',
                        'payment_date' => now()->toDateString(),
                        'method' => 'cash',
                        'recorded_by_user_id' => $manager->id,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame(1, SupplierPayment::where('business_id', $tenantId)->count());
    }
}
