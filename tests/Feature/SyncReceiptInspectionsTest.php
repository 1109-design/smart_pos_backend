<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\PurchaseOrder;
use App\Models\ReceiptInspection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncReceiptInspectionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
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

    private function makePurchaseOrder(string $tenantId): PurchaseOrder
    {
        return PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'po_number' => 'PO-'.strtoupper(Str::random(5)),
            'status' => 'received',
            'created_by_user_id' => (string) Str::uuid(),
        ]);
    }

    private function push(string $token, string $id, string $tenantId, string $poId, string $result, float $rejected)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'receipt_inspections',
                    'uuid' => $id,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'purchase_order_id' => $poId,
                        'purchase_order_item_id' => null,
                        'product_id' => (string) Str::uuid(),
                        'product_name' => 'Cement 50kg',
                        'delivered_qty' => 10,
                        'accepted_qty' => 10 - $rejected,
                        'rejected_qty' => $rejected,
                        'result' => $result,
                        'notes' => $rejected > 0 ? 'bags torn' : null,
                        'inspected_by_user_id' => 'user-1',
                        'inspected_by_name' => 'Clerk',
                        'inspected_at' => '2026-09-28T10:15:00',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_inspection_is_stored(): void
    {
        $token = $this->actingDeviceToken('tenant-insp-1');
        $po = $this->makePurchaseOrder('tenant-insp-1');
        $id = (string) Str::uuid();

        $this->push($token, $id, 'tenant-insp-1', $po->id, 'damaged', 2)
            ->assertOk()
            ->assertJsonCount(1, 'accepted');

        $row = ReceiptInspection::findOrFail($id);
        $this->assertSame('damaged', $row->result);
        $this->assertEquals(8, (float) $row->accepted_qty);
        $this->assertEquals(2, (float) $row->rejected_qty);
        $this->assertSame('bags torn', $row->notes);
        $this->assertCount(1, $po->inspections);
    }

    public function test_resent_inspection_never_rewrites_the_original(): void
    {
        $token = $this->actingDeviceToken('tenant-insp-2');
        $po = $this->makePurchaseOrder('tenant-insp-2');
        $id = (string) Str::uuid();

        $this->push($token, $id, 'tenant-insp-2', $po->id, 'damaged', 2)->assertOk();
        $this->push($token, $id, 'tenant-insp-2', $po->id, 'passed', 0)->assertOk();

        $this->assertSame('damaged', ReceiptInspection::findOrFail($id)->result);
    }

    public function test_delete_is_ignored(): void
    {
        $token = $this->actingDeviceToken('tenant-insp-3');
        $po = $this->makePurchaseOrder('tenant-insp-3');
        $id = (string) Str::uuid();
        $this->push($token, $id, 'tenant-insp-3', $po->id, 'passed', 0)->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'receipt_inspections',
                    'uuid' => $id,
                    'operation' => 'delete',
                    'payload' => [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertNotNull(ReceiptInspection::find($id));
    }

    public function test_inspection_for_another_tenants_po_is_rejected(): void
    {
        $token = $this->actingDeviceToken('tenant-insp-4');
        Tenant::create(['id' => 'tenant-insp-other', 'business_name' => 'x', 'owner_email' => 'x@example.com']);
        $foreignPo = $this->makePurchaseOrder('tenant-insp-other');
        $id = (string) Str::uuid();

        $this->push($token, $id, 'tenant-insp-4', $foreignPo->id, 'passed', 0)
            ->assertJsonCount(0, 'accepted');

        $this->assertNull(ReceiptInspection::find($id));
    }
}
