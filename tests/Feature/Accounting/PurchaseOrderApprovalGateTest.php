<?php

namespace Tests\Feature\Accounting;

use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PO approval is decided in the app — Approval Setup's 'purchase_order'
 * stages and the app's Approvals inbox. End to end through the real
 * /api/v1/sync/push endpoint: the server never holds a PO the app sent, and
 * a decision made in the app's inbox releases or cancels a PO the app held
 * at pending_approval, so server and devices agree.
 */
class PurchaseOrderApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-po-gate';

    private string $creatorId;

    private string $deviceUserId;

    private function actingDeviceToken(): string
    {
        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => $this->tenantId.'@example.com']);
        Business::create(['id' => $this->tenantId, 'name' => $this->tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->tenantId);

        $user = User::factory()->create(['business_id' => $this->tenantId, 'email' => $this->tenantId.'-owner@example.com']);
        $this->deviceUserId = $user->id;
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        $this->creatorId = (string) Str::uuid();

        return $plain;
    }

    private function setThreshold(float $amount): void
    {
        Business::where('id', $this->tenantId)->update(['workflow_settings' => ['po_approval_threshold' => $amount]]);
    }

    private function submitPo(string $token, string $poId, string $supplierId, float $total, string $status = 'sent'): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'purchase_orders',
                    'uuid' => $poId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $this->tenantId,
                        'supplier_id' => $supplierId,
                        'supplier_name' => 'Acme Supplies',
                        'po_number' => 'PO-0001',
                        'status' => $status,
                        'total_ordered' => $total,
                        'created_by_user_id' => $this->creatorId,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();
    }

    private function pushApprovalRequest(string $token, string $requestId, string $poId, string $status): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'approval_requests',
                    'uuid' => $requestId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $this->tenantId,
                        'subject_type' => 'PurchaseOrder',
                        'subject_id' => $poId,
                        'action' => 'approve_purchase_order',
                        'requested_by_user_id' => $this->creatorId,
                        'status' => $status,
                        'approver_user_id' => $status === 'pending' ? null : $this->deviceUserId,
                        'approved_at' => $status === 'pending' ? null : now()->toIso8601String(),
                        'payload_json' => ['po_number' => 'PO-0001'],
                        'current_level' => 1,
                        'max_level' => 1,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();
    }

    public function test_a_po_the_app_sent_is_never_held_by_a_server_threshold(): void
    {
        $token = $this->actingDeviceToken();
        Business::where('id', $this->tenantId)->update(['workflow_settings' => ['po_approval_threshold' => 1000]]);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();

        $this->submitPo($token, $poId, $supplier->id, 999999);

        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_approving_in_the_app_releases_a_held_po_on_the_server(): void
    {
        $token = $this->actingDeviceToken();
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        // The app held it: request raised, PO at pending_approval.
        $this->pushApprovalRequest($token, $requestId, $poId, 'pending');
        $this->submitPo($token, $poId, $supplier->id, 1500, 'pending_approval');
        $this->assertSame('pending_approval', PurchaseOrder::find($poId)->status);

        // An approver approves it in the app's inbox — only the decision
        // has synced so far; the server releases the PO from it alone.
        $this->pushApprovalRequest($token, $requestId, $poId, 'approved');

        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'purchase_orders', 'record_uuid' => $poId]);
        $this->assertDatabaseHas('po_audit_logs', ['po_id' => $poId, 'action' => 'approved']);

        // The app's own 'sent' push that follows is accepted, not rejected.
        $this->submitPo($token, $poId, $supplier->id, 1500, 'sent');
        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
    }

    public function test_a_held_po_accepts_the_apps_sent_even_before_the_decision_syncs(): void
    {
        $token = $this->actingDeviceToken();
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();

        $this->submitPo($token, $poId, $supplier->id, 1500, 'pending_approval');
        $this->submitPo($token, $poId, $supplier->id, 1500, 'sent');

        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
    }

    public function test_rejecting_in_the_app_cancels_the_po_on_the_server(): void
    {
        $token = $this->actingDeviceToken();
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        $this->pushApprovalRequest($token, $requestId, $poId, 'pending');
        $this->submitPo($token, $poId, $supplier->id, 1500, 'pending_approval');
        $this->pushApprovalRequest($token, $requestId, $poId, 'rejected');

        $this->assertSame('cancelled', PurchaseOrder::find($poId)->status);
        $this->assertDatabaseHas('po_audit_logs', ['po_id' => $poId, 'action' => 'rejected']);
    }
}
