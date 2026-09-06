<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRJ·04 — the BackOffice side of job costing: creating/closing a project,
 * and its cost build-up report (stock issued via a requisition + direct
 * expenses tagged to the job).
 */
class BackOfficeProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        Business::firstOrCreate(['id' => $tenantId], ['name' => $tenantId, 'currency_code' => 'USD']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com', 'is_active' => true,
        ]);

        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => $role, 'business_name' => $tenantId,
            'currency_code' => 'USD',
        ]]);

        return $user;
    }

    public function test_owner_can_create_a_project(): void
    {
        $tenantId = 'tenant-proj-1';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/projects', [
            'name' => 'Shopfront Renovation',
            'reference' => 'JOB-42',
            'budget' => 1000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('projects', [
            'business_id' => $tenantId, 'name' => 'Shopfront Renovation', 'reference' => 'JOB-42', 'status' => 'active',
        ]);
    }

    public function test_index_reports_spent_from_issued_requisitions_and_expenses(): void
    {
        $tenantId = 'tenant-proj-2';
        $this->actingBackOfficeSession($tenantId);

        $project = Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse Fit-out',
            'budget' => 500, 'status' => 'active', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $requisition = Requisition::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'requisition_number' => 'REQ-001',
            'location_id' => (string) Str::uuid(), 'purpose' => 'project', 'project_id' => $project->id,
            'status' => 'issued', 'requested_by_user_id' => (string) Str::uuid(),
        ]);
        RequisitionItem::create([
            'id' => (string) Str::uuid(), 'requisition_id' => $requisition->id, 'product_id' => (string) Str::uuid(),
            'product_name' => 'Timber', 'quantity_requested' => 10, 'quantity_issued' => 10, 'unit_cost' => 15,
        ]);

        Expense::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'recorded_by_user_id' => (string) Str::uuid(),
            'project_id' => $project->id, 'category' => 'transport', 'description' => 'Delivery truck',
            'amount' => 50, 'currency_code' => 'USD', 'base_equivalent' => 50, 'expense_date' => now(),
        ]);

        $response = $this->get('/office/projects');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('projects.0.spent', 200) // 10 * 15 + 50
            ->where('projects.0.budget', 500)
        );
    }

    public function test_show_lists_cost_lines_and_excludes_a_not_yet_issued_requisition(): void
    {
        $tenantId = 'tenant-proj-3';
        $this->actingBackOfficeSession($tenantId);

        $project = Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Kitchen Job',
            'status' => 'active', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $issued = Requisition::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'requisition_number' => 'REQ-010',
            'location_id' => (string) Str::uuid(), 'purpose' => 'project', 'project_id' => $project->id,
            'status' => 'issued', 'requested_by_user_id' => (string) Str::uuid(), 'issued_at' => now(),
        ]);
        RequisitionItem::create([
            'id' => (string) Str::uuid(), 'requisition_id' => $issued->id, 'product_id' => (string) Str::uuid(),
            'product_name' => 'Tiles', 'quantity_requested' => 20, 'quantity_issued' => 20, 'unit_cost' => 3,
        ]);

        // Still pending — nothing has actually left the warehouse yet, so it
        // must not appear as a cost.
        $pending = Requisition::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'requisition_number' => 'REQ-011',
            'location_id' => (string) Str::uuid(), 'purpose' => 'project', 'project_id' => $project->id,
            'status' => 'pending', 'requested_by_user_id' => (string) Str::uuid(),
        ]);
        RequisitionItem::create([
            'id' => (string) Str::uuid(), 'requisition_id' => $pending->id, 'product_id' => (string) Str::uuid(),
            'product_name' => 'Grout', 'quantity_requested' => 5, 'quantity_issued' => 0, 'unit_cost' => 3,
        ]);

        $response = $this->get("/office/projects/{$project->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('lines', 1)
            ->where('lines.0.description', 'Tiles')
            ->where('total_cost', 60)
        );
    }

    public function test_owner_can_close_and_reopen_a_project(): void
    {
        $tenantId = 'tenant-proj-4';
        $this->actingBackOfficeSession($tenantId);

        $project = Project::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Done Job',
            'status' => 'active', 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->post("/office/projects/{$project->id}/close")->assertRedirect();
        $this->assertSame('closed', Project::find($project->id)->status);

        $this->post("/office/projects/{$project->id}/reopen")->assertRedirect();
        $this->assertSame('active', Project::find($project->id)->status);
    }

    public function test_cashier_cannot_manage_projects(): void
    {
        $tenantId = 'tenant-proj-5';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/projects')->assertForbidden();
        $this->post('/office/projects', ['name' => 'Nope'])->assertForbidden();
    }
}
