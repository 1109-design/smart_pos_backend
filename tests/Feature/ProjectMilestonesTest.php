<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\MilestoneTask;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectMilestonesTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com',
            'is_active' => true,
        ]);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => 'business_owner',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    public function test_percent_complete_is_derived_from_tasks_not_stored(): void
    {
        $project = Project::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-milestone-1', 'name' => 'Warehouse Build', 'created_by_user_id' => (string) Str::uuid()]);
        $milestone = ProjectMilestone::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'title' => 'Foundation']);

        $this->assertSame(0, $milestone->fresh()->load('tasks')->percentComplete());

        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestone->id, 'title' => 'Dig trench', 'is_done' => true]);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestone->id, 'title' => 'Pour concrete', 'is_done' => false]);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestone->id, 'title' => 'Cure', 'is_done' => false]);

        $this->assertSame(33, $milestone->fresh()->load('tasks')->percentComplete());

        MilestoneTask::where('milestone_id', $milestone->id)->update(['is_done' => true]);
        $this->assertSame(100, $milestone->fresh()->load('tasks')->percentComplete());
    }

    public function test_milestone_is_overdue_only_when_target_date_passed_and_not_complete(): void
    {
        $project = Project::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-milestone-2', 'name' => 'Fit-out', 'created_by_user_id' => (string) Str::uuid()]);

        $overdueMilestone = ProjectMilestone::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'title' => 'Wiring', 'target_date' => now()->subDays(3),
        ]);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $overdueMilestone->id, 'title' => 'Run cable', 'is_done' => false]);
        $this->assertTrue($overdueMilestone->fresh()->load('tasks')->isOverdue());

        $overdueMilestone->fresh()->tasks()->update(['is_done' => true]);
        $this->assertFalse($overdueMilestone->fresh()->load('tasks')->isOverdue(), 'a fully completed milestone is never overdue, even past its date');

        $futureMilestone = ProjectMilestone::create([
            'id' => (string) Str::uuid(), 'project_id' => $project->id, 'title' => 'Painting', 'target_date' => now()->addDays(5),
        ]);
        $this->assertFalse($futureMilestone->fresh()->load('tasks')->isOverdue());
    }

    public function test_sync_processor_upserts_milestone_and_task_and_cascades_delete(): void
    {
        $project = Project::create(['id' => (string) Str::uuid(), 'business_id' => 'tenant-milestone-3', 'name' => 'Renovation', 'created_by_user_id' => (string) Str::uuid()]);
        $milestoneId = (string) Str::uuid();

        app(SyncProcessor::class)->process('project_milestones', $milestoneId, 'upsert', [
            'business_id' => 'tenant-milestone-3',
            'project_id' => $project->id,
            'title' => 'Demolition',
            'target_date' => now()->toDateString(),
        ]);
        $this->assertDatabaseHas('project_milestones', ['id' => $milestoneId, 'title' => 'Demolition']);

        $taskId = (string) Str::uuid();
        app(SyncProcessor::class)->process('milestone_tasks', $taskId, 'upsert', [
            'business_id' => 'tenant-milestone-3',
            'milestone_id' => $milestoneId,
            'title' => 'Strip old fittings',
            'is_done' => false,
        ]);
        $this->assertDatabaseHas('milestone_tasks', ['id' => $taskId, 'title' => 'Strip old fittings']);

        // Deleting the milestone is a bulk query-builder delete server-side —
        // it must explicitly cascade to the task rather than relying on a
        // model event that a bulk delete never fires.
        // A real device push has business_id injected by SyncController
        // before it ever reaches SyncProcessor (see
        // test_push_cannot_plant_a_new_record_under_another_businesss_id in
        // SyncOwnershipGuardTest) — supply it directly here since this test
        // calls the processor without going through that HTTP layer.
        app(SyncProcessor::class)->process('project_milestones', $milestoneId, 'delete', [
            'business_id' => 'tenant-milestone-3',
        ]);
        $this->assertDatabaseMissing('project_milestones', ['id' => $milestoneId]);
        $this->assertDatabaseMissing('milestone_tasks', ['id' => $taskId]);
    }

    public function test_backoffice_project_show_reports_task_weighted_overall_progress(): void
    {
        $tenantId = 'tenant-milestone-4';
        $this->actingBackOfficeSession($tenantId);

        $project = Project::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Client Fit-out', 'created_by_user_id' => (string) Str::uuid()]);

        // Milestone A: 1 of 1 tasks done. Milestone B: 1 of 4 tasks done.
        // A plain average-of-milestones would read 62.5%; task-weighted
        // reads 2/5 = 40% — proving it's genuinely weighted by task count.
        $milestoneA = ProjectMilestone::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'title' => 'A']);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestoneA->id, 'title' => 'Only task', 'is_done' => true]);

        $milestoneB = ProjectMilestone::create(['id' => (string) Str::uuid(), 'project_id' => $project->id, 'title' => 'B']);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestoneB->id, 'title' => 'Task 1', 'is_done' => true]);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestoneB->id, 'title' => 'Task 2', 'is_done' => false]);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestoneB->id, 'title' => 'Task 3', 'is_done' => false]);
        MilestoneTask::create(['id' => (string) Str::uuid(), 'milestone_id' => $milestoneB->id, 'title' => 'Task 4', 'is_done' => false]);

        $response = $this->get("/office/projects/{$project->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/ProjectShow')
            ->where('overall_progress', 40)
            ->has('milestones', 2)
        );
    }
}
