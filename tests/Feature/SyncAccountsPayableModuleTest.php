<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP module — end-to-end sync push coverage for the new
 * goods_received_vouchers/grv_items/supplier_invoices/
 * supplier_invoice_lines/supplier_payment_allocations tables: the fraud
 * escalation guard (same class as SyncSupplierPaymentEscalationGuardTest)
 * and a full parent-then-child push round trip.
 */
class SyncAccountsPayableModuleTest extends TestCase
{
    use RefreshDatabase;

    private function actingDevice(string $tenantId, string $role): array
    {
        $user = User::factory()->create(['email' => $tenantId.'-'.$role.'@example.com']);
        $user->assignRole($role);
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];
        Device::create([
            'tenant_id' => $tenantId,
            'name' => ucfirst($role).' Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return [$user, $plain];
    }

    public function test_a_cashier_cannot_push_a_supplier_invoice(): void
    {
        $tenantId = 'tenant-ap-invoice-escalation';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        [$cashier, $plain] = $this->actingDevice($tenantId, 'cashier');

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'supplier_invoices',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'supplier_id' => $supplier->id,
                        'invoice_number' => 'INV-FRAUD-1',
                        'invoice_date' => now()->toDateString(),
                        'total' => 999999,
                        'status' => 'posted',
                        'created_by_user_id' => $cashier->id,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'supplier_invoices: recording a supplier invoice requires owner or manager access.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame(0, SupplierInvoice::where('business_id', $tenantId)->count());
    }

    public function test_full_procure_to_pay_round_trip_via_sync_push(): void
    {
        $tenantId = 'tenant-ap-full-lifecycle';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);

        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        [$manager, $plain] = $this->actingDevice($tenantId, 'manager');
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-TEST-1',
            'status' => 'received',
            'created_by_user_id' => $manager->id,
        ]);

        $grvId = (string) Str::uuid();
        $grvItemId = (string) Str::uuid();
        $invoiceId = (string) Str::uuid();
        $invoiceLineId = (string) Str::uuid();
        $paymentId = (string) Str::uuid();
        $allocationId = (string) Str::uuid();

        $records = [
            [
                'table' => 'goods_received_vouchers',
                'uuid' => $grvId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'grv_number' => 'GRV-2026-00000001',
                    'purchase_order_id' => $po->id,
                    'supplier_id' => $supplier->id,
                    'received_date' => now()->toDateString(),
                ],
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'grv_items',
                'uuid' => $grvItemId,
                'operation' => 'upsert',
                'payload' => [
                    'grv_id' => $grvId,
                    'stock_movement_id' => (string) Str::uuid(),
                    'product_id' => (string) Str::uuid(),
                    'product_name' => 'Widget',
                    'quantity_received' => 10,
                    'quantity_accepted' => 10,
                    'unit_cost' => 5,
                ],
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'supplier_invoices',
                'uuid' => $invoiceId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => $po->id,
                    'invoice_number' => 'INV-1001',
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'currency_code' => 'USD',
                    'exchange_rate' => 1,
                    'subtotal' => 50,
                    'total' => 50,
                    'status' => 'approved',
                    'match_status' => 'matched',
                    'created_by_user_id' => $manager->id,
                ],
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'supplier_invoice_lines',
                'uuid' => $invoiceLineId,
                'operation' => 'upsert',
                'payload' => [
                    'supplier_invoice_id' => $invoiceId,
                    'grv_item_id' => $grvItemId,
                    'description' => 'Widget',
                    'quantity' => 10,
                    'unit_cost' => 5,
                    'line_total' => 50,
                ],
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'supplier_payments',
                'uuid' => $paymentId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'supplier_id' => $supplier->id,
                    'amount' => 50,
                    'currency_code' => 'USD',
                    'payment_date' => now()->toDateString(),
                    'method' => 'bank',
                    'recorded_by_user_id' => $manager->id,
                ],
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'table' => 'supplier_payment_allocations',
                'uuid' => $allocationId,
                'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId,
                    'supplier_payment_id' => $paymentId,
                    'supplier_invoice_id' => $invoiceId,
                    'amount' => 50,
                    'created_by_user_id' => $manager->id,
                ],
                'updated_at' => now()->toIso8601String(),
            ],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/sync/push', ['records' => $records]);

        $response->assertOk();
        $this->assertCount(6, $response->json('accepted'), (string) json_encode($response->json('errors')));

        $this->assertSame(1, GoodsReceivedVoucher::where('id', $grvId)->count());
        $this->assertSame(1, GrvItem::where('id', $grvItemId)->where('grv_id', $grvId)->count());
        $this->assertSame(1, SupplierInvoice::where('id', $invoiceId)->where('purchase_order_id', $po->id)->count());
        $this->assertSame(1, SupplierInvoiceLine::where('id', $invoiceLineId)->where('grv_item_id', $grvItemId)->count());
        $this->assertSame(1, SupplierPayment::where('id', $paymentId)->count());
        $this->assertSame(1, SupplierPaymentAllocation::where('id', $allocationId)->where('amount', 50)->count());
    }

    public function test_a_child_record_cannot_be_attached_to_another_tenants_parent(): void
    {
        $tenantA = 'tenant-ap-isolation-a';
        $tenantB = 'tenant-ap-isolation-b';
        foreach ([$tenantA, $tenantB] as $t) {
            Tenant::create(['id' => $t, 'business_name' => $t, 'owner_email' => $t.'@example.com']);
            Business::create(['id' => $t, 'name' => $t, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
            (new ChartOfAccountsSeeder)->seedForBusiness($t);
        }
        $this->seed(RolesAndPermissionsSeeder::class);

        $supplierB = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantB, 'name' => 'Other Tenant Supplier']);
        $invoiceB = SupplierInvoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantB,
            'supplier_id' => $supplierB->id,
            'grv_id' => null,
            'invoice_number' => 'INV-B-1',
            'invoice_date' => now()->toDateString(),
            'amount' => 100,
        ]);

        [, $plainA] = $this->actingDevice($tenantA, 'manager');

        $response = $this->withHeader('Authorization', 'Bearer '.$plainA)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'supplier_invoice_lines',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'supplier_invoice_id' => $invoiceB->id,
                        'description' => 'Hijack attempt',
                        'quantity' => 1,
                        'unit_cost' => 1,
                        'line_total' => 1,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertNotEmpty($response->json('errors'));
    }
}
