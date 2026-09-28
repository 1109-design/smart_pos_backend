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
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchasing & Cash Vault Blueprint, part D — end to end through the real
 * /api/v1/sync/push endpoint (the till's actual submission path) proving a
 * PO over the configured threshold is held at pending_approval and raises a
 * remote ApprovalRequest, and that resolving it (via the same ApprovalService
 * the generic Approvals page uses) releases or cancels the order.
 */
class PurchaseOrderApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-po-gate';

    private string $creatorId;

    private function actingDeviceToken(): string
    {
        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => $this->tenantId.'@example.com']);
        Business::create(['id' => $this->tenantId, 'name' => $this->tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->tenantId);

        $user = User::factory()->create(['email' => $this->tenantId.'-owner@example.com']);
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

    private function submitPo(string $token, string $poId, string $supplierId, float $total): void
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
                        'status' => 'sent',
                        'total_ordered' => $total,
                        'created_by_user_id' => $this->creatorId,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();
    }

    public function test_a_po_under_the_threshold_is_sent_immediately(): void
    {
        $token = $this->actingDeviceToken();
        $this->setThreshold(1000);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();

        $this->submitPo($token, $poId, $supplier->id, 500);

        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_a_po_over_the_threshold_is_held_and_raises_an_approval_request(): void
    {
        $token = $this->actingDeviceToken();
        $this->setThreshold(1000);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();

        $this->submitPo($token, $poId, $supplier->id, 1500);

        $this->assertSame('pending_approval', PurchaseOrder::find($poId)->status);

        $request = ApprovalRequest::where('subject_id', $poId)->first();
        $this->assertNotNull($request);
        $this->assertSame('PurchaseOrder', $request->subject_type);
        $this->assertSame('approve_purchase_order', $request->action);
        $this->assertSame('pending', $request->status);
        $this->assertSame($this->creatorId, $request->requested_by_user_id);
        $this->assertSame(1500, $request->payload_json['total_ordered']);

        $this->assertDatabaseHas('po_audit_logs', ['po_id' => $poId, 'action' => 'pending_approval']);
    }

    public function test_no_threshold_configured_never_gates_a_po(): void
    {
        $token = $this->actingDeviceToken();
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();

        $this->submitPo($token, $poId, $supplier->id, 999999);

        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
    }

    public function test_a_stale_resync_of_the_original_sent_payload_does_not_undo_the_hold(): void
    {
        $token = $this->actingDeviceToken();
        $this->setThreshold(1000);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();

        $this->submitPo($token, $poId, $supplier->id, 1500);
        $this->assertSame('pending_approval', PurchaseOrder::find($poId)->status);

        // The till hasn't pulled the new status yet — its outbox retries the
        // exact same 'sent' payload a second time.
        $this->submitPo($token, $poId, $supplier->id, 1500);

        $this->assertSame('pending_approval', PurchaseOrder::find($poId)->status);
        $this->assertSame(1, ApprovalRequest::where('subject_id', $poId)->count(), 'only one approval request should ever be raised');
    }

    public function test_approving_the_request_releases_the_po_and_broadcasts_the_new_status(): void
    {
        $token = $this->actingDeviceToken();
        $this->setThreshold(1000);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();
        $this->submitPo($token, $poId, $supplier->id, 1500);

        $request = ApprovalRequest::where('subject_id', $poId)->firstOrFail();
        $owner = User::factory()->create(['business_id' => $this->tenantId]);

        app(ApprovalService::class)->resolve($request->id, $owner->id, 'approved');

        $this->assertSame('sent', PurchaseOrder::find($poId)->status);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'purchase_orders', 'record_uuid' => $poId]);
        $this->assertDatabaseHas('po_audit_logs', ['po_id' => $poId, 'action' => 'approved']);
    }

    public function test_rejecting_the_request_cancels_the_po(): void
    {
        $token = $this->actingDeviceToken();
        $this->setThreshold(1000);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'name' => 'Acme Supplies']);
        $poId = (string) Str::uuid();
        $this->submitPo($token, $poId, $supplier->id, 1500);

        $request = ApprovalRequest::where('subject_id', $poId)->firstOrFail();
        $owner = User::factory()->create(['business_id' => $this->tenantId]);

        app(ApprovalService::class)->resolve($request->id, $owner->id, 'rejected', 'Too expensive right now');

        $this->assertSame('cancelled', PurchaseOrder::find($poId)->status);
        $this->assertDatabaseHas('po_audit_logs', ['po_id' => $poId, 'action' => 'rejected']);
    }
}
