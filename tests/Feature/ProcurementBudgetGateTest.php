<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\ProcurementBudget;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUR·02 — a period procurement budget gates a PO submission the same way
 * the flat per-PO threshold already does (SyncProcessor::
 * gatePurchaseOrderStatus() / PurchaseOrderApprovalGate), just for the case
 * that gate can't catch: many individually-small POs adding up over a
 * period.
 */
class ProcurementBudgetGateTest extends TestCase
{
    use RefreshDatabase;

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

    private function pushPo(string $token, string $tenantId, string $poId, string $poNumber, float $total, string $userId): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'purchase_orders', 'uuid' => $poId, 'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId, 'supplier_name' => 'ACME Supplies',
                    'po_number' => $poNumber, 'status' => 'sent', 'total_ordered' => $total,
                    'created_by_user_id' => $userId,
                ],
                'updated_at' => now()->toIso8601String(),
            ]],
        ]);
    }

    public function test_a_po_within_budget_sends_straight_through(): void
    {
        $tenantId = 'tenant-budget-1';
        $token = $this->actingDeviceToken($tenantId);
        $userId = (string) Str::uuid();

        ProcurementBudget::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Sept 2026',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'amount' => 1000, 'created_by_user_id' => $userId,
        ]);

        $this->pushPo($token, $tenantId, (string) Str::uuid(), 'PO-1', 400, $userId)->assertOk();

        $po = PurchaseOrder::where('business_id', $tenantId)->first();
        $this->assertSame('sent', $po->status);
    }

    public function test_a_po_that_would_exceed_the_period_budget_is_held_for_approval(): void
    {
        $tenantId = 'tenant-budget-2';
        $token = $this->actingDeviceToken($tenantId);
        $userId = (string) Str::uuid();

        $budget = ProcurementBudget::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Sept 2026',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'amount' => 1000, 'created_by_user_id' => $userId,
        ]);

        // First PO: 700, well within budget.
        $this->pushPo($token, $tenantId, (string) Str::uuid(), 'PO-1', 700, $userId)->assertOk();
        // Second PO: another 500 would bring the period total to 1200 > 1000.
        $secondPoId = (string) Str::uuid();
        $this->pushPo($token, $tenantId, $secondPoId, 'PO-2', 500, $userId)->assertOk();

        $secondPo = PurchaseOrder::findOrFail($secondPoId);
        $this->assertSame('pending_approval', $secondPo->status);

        $request = ApprovalRequest::where('subject_id', $secondPoId)->first();
        $this->assertNotNull($request);
        $this->assertSame('approve_purchase_order', $request->action);
        $this->assertStringContainsString($budget->name, $request->payload_json['reason']);

        // Budget spend only counts what's actually been sent (700), not the
        // held PO — spentSoFar() must reflect reality, not double-count a
        // PO that never actually went out.
        $this->assertSame(700.0, $budget->fresh()->spentSoFar());
    }

    public function test_approving_a_budget_held_po_releases_it_to_sent(): void
    {
        $tenantId = 'tenant-budget-3';
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        $owner = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-owner2@example.com', 'is_active' => true]);

        $token = $this->actingDeviceToken($tenantId.'-device');
        Device::where('tenant_id', $tenantId.'-device')->update(['tenant_id' => $tenantId]);

        ProcurementBudget::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Sept 2026',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'amount' => 100, 'created_by_user_id' => $owner->id,
        ]);

        $poId = (string) Str::uuid();
        $this->pushPo($token, $tenantId, $poId, 'PO-1', 200, $owner->id)->assertOk();
        $this->assertSame('pending_approval', PurchaseOrder::findOrFail($poId)->status);

        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $owner->id, 'user_name' => $owner->name,
            'user_email' => $owner->email, 'role' => 'business_owner', 'business_name' => $tenantId, 'currency_code' => 'USD',
        ]]);
        $request = ApprovalRequest::where('subject_id', $poId)->firstOrFail();
        $this->post("/office/approvals/{$request->id}/approve")->assertRedirect();

        $this->assertSame('sent', PurchaseOrder::findOrFail($poId)->fresh()->status);
    }

    public function test_a_budget_outside_its_period_does_not_gate(): void
    {
        $tenantId = 'tenant-budget-4';
        $token = $this->actingDeviceToken($tenantId);
        $userId = (string) Str::uuid();

        ProcurementBudget::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Last Year',
            'period_start' => now()->subYear()->startOfMonth(), 'period_end' => now()->subYear()->endOfMonth(),
            'amount' => 10, 'created_by_user_id' => $userId,
        ]);

        $this->pushPo($token, $tenantId, (string) Str::uuid(), 'PO-1', 5000, $userId)->assertOk();

        $po = PurchaseOrder::where('business_id', $tenantId)->first();
        $this->assertSame('sent', $po->status);
    }

    public function test_spent_so_far_only_counts_sent_partial_and_received_statuses(): void
    {
        $tenantId = 'tenant-budget-5';
        Business::create(['id' => $tenantId, 'name' => $tenantId]);
        $userId = (string) Str::uuid();

        $budget = ProcurementBudget::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Sept 2026',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'amount' => 1000, 'created_by_user_id' => $userId,
        ]);

        foreach (['draft' => 100, 'sent' => 200, 'partial' => 300, 'received' => 400, 'cancelled' => 500, 'pending_approval' => 600] as $status => $amount) {
            PurchaseOrder::create([
                'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'po_number' => "PO-$status",
                'status' => $status, 'total_ordered' => $amount, 'created_by_user_id' => $userId,
            ]);
        }

        // Only sent (200) + partial (300) + received (400) = 900.
        $this->assertSame(900.0, $budget->spentSoFar());
        $this->assertSame(100.0, $budget->remaining());
    }
}
