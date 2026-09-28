<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ProcurementBudget;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeProcurementBudgetsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-user@example.com', 'is_active' => true]);
        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => $role, 'business_name' => $tenantId, 'currency_code' => 'USD',
        ]]);

        return $user;
    }

    public function test_a_budget_can_be_created_and_removed(): void
    {
        $tenantId = 'tenant-bo-budget-1';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/procurement-budgets', [
            'name' => 'Sept 2026',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'amount' => 50000,
        ])->assertRedirect();

        $budget = ProcurementBudget::where('business_id', $tenantId)->first();
        $this->assertNotNull($budget);
        $this->assertSame('Sept 2026', $budget->name);

        $this->delete("/office/procurement-budgets/{$budget->id}")->assertRedirect();
        $this->assertDatabaseMissing('procurement_budgets', ['id' => $budget->id]);
    }

    /**
     * A budget created (or removed) from BackOffice must reach any till,
     * not just live in the tenant's browser session — this is what lets
     * the till's own Procurement Budgets screen see a BackOffice-created
     * budget on its next pull, and vice versa.
     */
    public function test_create_and_delete_both_publish_a_sync_record(): void
    {
        $tenantId = 'tenant-bo-budget-sync';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/procurement-budgets', [
            'name' => 'Sync Check',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'amount' => 20000,
        ])->assertRedirect();

        $budget = ProcurementBudget::where('business_id', $tenantId)->firstOrFail();

        $createRecord = SyncRecord::where('table_name', 'procurement_budgets')
            ->where('record_uuid', $budget->id)
            ->where('operation', 'upsert')
            ->first();
        $this->assertNotNull($createRecord);
        $this->assertSame('Sync Check', $createRecord->payload['name']);

        $this->delete("/office/procurement-budgets/{$budget->id}")->assertRedirect();

        $deleteRecord = SyncRecord::where('table_name', 'procurement_budgets')
            ->where('record_uuid', $budget->id)
            ->where('operation', 'delete')
            ->first();
        $this->assertNotNull($deleteRecord);
    }

    public function test_period_end_before_start_is_rejected(): void
    {
        $tenantId = 'tenant-bo-budget-2';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/procurement-budgets', [
            'name' => 'Bad Range',
            'period_start' => '2026-09-30',
            'period_end' => '2026-09-01',
            'amount' => 1000,
        ])->assertSessionHasErrors('period_end');
    }

    public function test_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-bo-budget-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'DDDDDD']);
        $foreignBudget = ProcurementBudget::create([
            'id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Theirs',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'amount' => 1,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $tenantId = 'tenant-bo-budget-3';
        $this->actingBackOfficeSession($tenantId);

        $this->delete("/office/procurement-budgets/{$foreignBudget->id}")->assertNotFound();
        $this->assertDatabaseHas('procurement_budgets', ['id' => $foreignBudget->id]);
    }
}
