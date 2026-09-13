<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ApprovalRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\PurchaseOrderApprovalGate;
use App\Services\BusinessProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enterprise approval-rule-engine audit follow-up — proves the full loop,
 * not just separation of duties: request() now attaches a rule_set_id
 * (via PurchaseOrderApprovalGate passing process: 'purchase_order' +
 * amount context), and resolve() looks that rule's required_role up and
 * enforces it through ApprovalRuleEngine::canApprove(). Before this, ANY
 * MANAGE_APPROVALS holder — even a cashier, if ever granted that coarse
 * permission — could clear a PO approval the seeded rule says needs
 * branch_manager-or-higher authority.
 *
 * Uses BusinessProvisioner (not a bare Tenant::create) deliberately — only
 * that path actually seeds DefaultApprovalRulesSeeder's rule sets, so this
 * is the only way to exercise the rule genuinely being resolved end to end.
 */
class ApprovalRuleBasedAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function provisionBusiness(): Tenant
    {
        return app(BusinessProvisioner::class)->provision([
            'business_name' => 'Acme Retail '.Str::random(6),
            'owner_email' => Str::random(8).'@acme.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->addMonth(),
            'currency_code' => 'USD',
            'admin_name' => 'Ada Owner',
            'admin_pin' => '4321',
        ]);
    }

    private function raisePoApproval(string $tenantId, string $requesterId): ApprovalRequest
    {
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $po = PurchaseOrder::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-000001',
            'status' => 'pending_approval',
            'total_ordered' => 5000,
            'created_by_user_id' => $requesterId,
        ]);

        app(PurchaseOrderApprovalGate::class)->requestApproval($po->id);

        return ApprovalRequest::where('subject_id', $po->id)->firstOrFail();
    }

    private function actingBackOfficeSession(string $tenantId, User $user): void
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => 'business_owner', 'business_name' => $tenantId, 'currency_code' => 'USD',
        ]]);
    }

    public function test_request_attaches_the_seeded_purchase_order_rule(): void
    {
        $tenant = $this->provisionBusiness();
        $requester = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);

        $request = $this->raisePoApproval($tenant->id, $requester->id);

        $this->assertNotNull($request->rule_set_id);
        $this->assertSame(1, $request->current_level);
        $this->assertNotNull($request->sla_due_at);
        $this->assertSame(5000.0, (float) $request->estimated_value);
    }

    public function test_a_cashier_cannot_approve_a_po_that_requires_branch_manager_authority(): void
    {
        $tenant = $this->provisionBusiness();
        $requester = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);
        $request = $this->raisePoApproval($tenant->id, $requester->id);

        // A cashier who was somehow also granted MANAGE_APPROVALS (e.g. a
        // misconfigured custom role) — the coarse permission check alone
        // would have let this through before this fix.
        $cashier = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);
        $cashier->assignRole('cashier');
        $this->actingBackOfficeSession($tenant->id, $cashier);

        $response = $this->post("/office/approvals/{$request->id}/approve");

        $response->assertRedirect();
        $response->assertSessionHasErrors('approval');
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_manager_can_approve_a_po_that_requires_branch_manager_authority(): void
    {
        $tenant = $this->provisionBusiness();
        $requester = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);
        $request = $this->raisePoApproval($tenant->id, $requester->id);

        // 'manager' sits at the same hierarchy level as 'branch_manager' —
        // see ApprovalRuleEngine::roleCanApproveFor()'s legacy-alias table.
        $manager = User::factory()->create(['business_id' => $tenant->id, 'email' => Str::random(8).'@x.com']);
        $manager->assignRole('manager');
        $this->actingBackOfficeSession($tenant->id, $manager);

        $response = $this->post("/office/approvals/{$request->id}/approve");

        $response->assertRedirect();
        $response->assertSessionMissing('errors');
        $this->assertSame('approved', $request->fresh()->status);
    }
}
