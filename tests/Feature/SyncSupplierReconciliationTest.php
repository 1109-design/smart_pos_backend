<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\Supplier;
use App\Models\SupplierReconciliation;
use App\Models\SupplierReconciliationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP module — spec §25 supplier statement reconciliation, sync push round
 * trip (mirrors SyncAccountsPayableModuleTest's shape).
 */
class SyncSupplierReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reconciliation_and_its_items_push_and_apply_correctly(): void
    {
        $tenantId = 'tenant-ap-reconciliation';
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

        $reconciliationId = (string) Str::uuid();
        $itemId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', [
                'records' => [
                    [
                        'table' => 'supplier_reconciliations',
                        'uuid' => $reconciliationId,
                        'operation' => 'upsert',
                        'payload' => [
                            'business_id' => $tenantId,
                            'supplier_id' => $supplier->id,
                            'statement_date' => now()->toDateString(),
                            'statement_closing_balance' => 1000,
                            'smart_pos_closing_balance' => 950,
                            'variance' => 50,
                            'status' => 'in_progress',
                            'reconciled_by_user_id' => $manager->id,
                        ],
                        'updated_at' => now()->toIso8601String(),
                    ],
                    [
                        'table' => 'supplier_reconciliation_items',
                        'uuid' => $itemId,
                        'operation' => 'upsert',
                        'payload' => [
                            'reconciliation_id' => $reconciliationId,
                            'document_type' => 'invoice',
                            'document_reference' => 'INV-999',
                            'document_date' => now()->toDateString(),
                            'supplier_amount' => 50,
                            'smart_pos_amount' => 0,
                            'difference' => 50,
                            'status' => 'missing_in_smartpos',
                        ],
                        'updated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('accepted'), (string) json_encode($response->json('errors')));
        $this->assertSame(1, SupplierReconciliation::where('id', $reconciliationId)->where('variance', 50)->count());
        $this->assertSame(1, SupplierReconciliationItem::where('id', $itemId)->where('reconciliation_id', $reconciliationId)->count());
    }
}
