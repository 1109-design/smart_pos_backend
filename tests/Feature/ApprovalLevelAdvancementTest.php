<?php

namespace Tests\Feature;

use App\Models\ApprovalGroup;
use App\Models\ApprovalGroupMember;
use App\Models\ApprovalRequestStageDecision;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Central Approval Stage Engine — proves the core defect this feature was
 * built to fix: ApprovalService::resolve() used to finalize a request on
 * its first decision no matter how many levels its rule set defined, and
 * silently reset rule_set_id/current_level/max_level back to their column
 * defaults on every sync upsert because it only ever echoed a partial
 * payload. Here a 2-stage refund with two different named approver groups
 * proves level 1 advances (stays pending, moves to level 2) instead of
 * finalizing, level 2 then genuinely finalizes it, and every rule-engine
 * field on the request row survives both writes intact.
 */
class ApprovalLevelAdvancementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $businessId): User
    {
        return User::factory()->create(['business_id' => $businessId, 'email' => Str::random(10).'@x.com']);
    }

    public function test_a_two_stage_named_group_refund_advances_then_finalizes(): void
    {
        $businessId = (string) Str::uuid();
        Tenant::create(['id' => $businessId, 'business_name' => $businessId, 'owner_email' => $businessId.'@example.com']);

        $requester = $this->makeUser($businessId);
        $stageOneApprover = $this->makeUser($businessId);
        $stageTwoApprover = $this->makeUser($businessId);

        $stageOneGroup = ApprovalGroup::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Sales Supervisors']);
        ApprovalGroupMember::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'group_id' => $stageOneGroup->id, 'user_id' => $stageOneApprover->id]);

        $stageTwoGroup = ApprovalGroup::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Branch Managers']);
        ApprovalGroupMember::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'group_id' => $stageTwoGroup->id, 'user_id' => $stageTwoApprover->id]);

        $ruleSet = ApprovalRuleSet::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'process' => 'refund', 'name' => 'Refund', 'is_enabled' => true]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 1, 'condition_type' => 'always', 'approval_group_id' => $stageOneGroup->id, 'sla_hours' => 1,
        ]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 2, 'condition_type' => 'always', 'approval_group_id' => $stageTwoGroup->id, 'sla_hours' => 4,
        ]);

        $service = app(ApprovalService::class);
        $request = $service->request(
            $businessId, 'Transaction', (string) Str::uuid(), 'refund', $requester->id,
            ['reason' => 'Customer returned item'], process: 'refund', context: ['amount' => 250.0],
        );

        $this->assertSame($ruleSet->id, $request->rule_set_id);
        $this->assertSame(1, $request->current_level);
        $this->assertSame(2, $request->max_level);

        // Stage 1: approving should ADVANCE, not finalize — this is the
        // confirmed bug. The request stays pending at level 2, still
        // carrying its rule_set_id/max_level/estimated_value.
        $afterStageOne = $service->resolve($request->id, $stageOneApprover->id, 'approved', 'Looks fine');

        $this->assertTrue($afterStageOne->isPending());
        $this->assertSame(2, $afterStageOne->current_level);
        $this->assertSame($ruleSet->id, $afterStageOne->rule_set_id, 'rule_set_id must survive the intermediate resolve, not reset to null');
        $this->assertSame(2, $afterStageOne->max_level, 'max_level must survive the intermediate resolve');
        $this->assertSame(250.0, (float) $afterStageOne->estimated_value, 'estimated_value must survive the intermediate resolve');
        $this->assertNull($afterStageOne->approver_user_id, 'not yet finally decided — still awaiting stage 2');

        // Stage 1 approver has no authority at stage 2's group.
        $this->expectExceptionMessage('This stage requires a member of the assigned approver group to decide it.');
        $service->resolve($request->id, $stageOneApprover->id, 'approved');
    }

    public function test_stage_two_finalizes_and_records_both_stage_decisions_with_no_conflict_flag(): void
    {
        $businessId = (string) Str::uuid();
        Tenant::create(['id' => $businessId, 'business_name' => $businessId, 'owner_email' => $businessId.'@example.com']);

        $requester = $this->makeUser($businessId);
        $stageOneApprover = $this->makeUser($businessId);
        $stageTwoApprover = $this->makeUser($businessId);

        $stageOneGroup = ApprovalGroup::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Sales Supervisors']);
        ApprovalGroupMember::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'group_id' => $stageOneGroup->id, 'user_id' => $stageOneApprover->id]);

        $stageTwoGroup = ApprovalGroup::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Branch Managers']);
        ApprovalGroupMember::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'group_id' => $stageTwoGroup->id, 'user_id' => $stageTwoApprover->id]);

        $ruleSet = ApprovalRuleSet::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'process' => 'refund', 'name' => 'Refund', 'is_enabled' => true]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 1, 'condition_type' => 'always', 'approval_group_id' => $stageOneGroup->id, 'sla_hours' => 1,
        ]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 2, 'condition_type' => 'always', 'approval_group_id' => $stageTwoGroup->id, 'sla_hours' => 4,
        ]);

        $service = app(ApprovalService::class);
        $request = $service->request(
            $businessId, 'Transaction', (string) Str::uuid(), 'refund', $requester->id,
            [], process: 'refund', context: ['amount' => 250.0],
        );

        $service->resolve($request->id, $stageOneApprover->id, 'approved');
        $final = $service->resolve($request->id, $stageTwoApprover->id, 'approved', 'Confirmed with customer');

        $this->assertSame('approved', $final->status);
        $this->assertSame($stageTwoApprover->id, $final->approver_user_id);

        $decisions = ApprovalRequestStageDecision::where('approval_request_id', $request->id)->orderBy('level')->get();
        $this->assertCount(2, $decisions);
        $this->assertSame(1, $decisions[0]->level);
        $this->assertSame($stageOneApprover->id, $decisions[0]->acted_by_user_id);
        $this->assertFalse($decisions[0]->same_approver_as_prior_stage);
        $this->assertSame(2, $decisions[1]->level);
        $this->assertSame($stageTwoApprover->id, $decisions[1]->acted_by_user_id);
        $this->assertFalse($decisions[1]->same_approver_as_prior_stage, 'different people cleared each stage — no conflict');
    }

    public function test_same_person_clearing_two_consecutive_stages_is_flagged_not_blocked(): void
    {
        $businessId = (string) Str::uuid();
        Tenant::create(['id' => $businessId, 'business_name' => $businessId, 'owner_email' => $businessId.'@example.com']);

        $requester = $this->makeUser($businessId);
        $versatileApprover = $this->makeUser($businessId);

        // The same person sits in BOTH stage groups — mirrors the
        // delegate-ends-up-covering-two-consecutive-stages scenario: the
        // decision was "allow, but flag for audit", never block.
        $stageOneGroup = ApprovalGroup::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Stage 1']);
        ApprovalGroupMember::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'group_id' => $stageOneGroup->id, 'user_id' => $versatileApprover->id]);

        $stageTwoGroup = ApprovalGroup::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Stage 2']);
        ApprovalGroupMember::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'group_id' => $stageTwoGroup->id, 'user_id' => $versatileApprover->id]);

        $ruleSet = ApprovalRuleSet::create(['id' => (string) Str::uuid(), 'business_id' => $businessId, 'process' => 'refund', 'name' => 'Refund', 'is_enabled' => true]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 1, 'condition_type' => 'always', 'approval_group_id' => $stageOneGroup->id,
            'require_different_user' => false,
        ]);
        ApprovalRule::create([
            'id' => (string) Str::uuid(), 'business_id' => $businessId, 'rule_set_id' => $ruleSet->id,
            'level' => 2, 'condition_type' => 'always', 'approval_group_id' => $stageTwoGroup->id,
            'require_different_user' => false,
        ]);

        $service = app(ApprovalService::class);
        $request = $service->request(
            $businessId, 'Transaction', (string) Str::uuid(), 'refund', $requester->id,
            [], process: 'refund', context: ['amount' => 250.0],
        );

        $service->resolve($request->id, $versatileApprover->id, 'approved');
        $final = $service->resolve($request->id, $versatileApprover->id, 'approved');

        $this->assertSame('approved', $final->status, 'allowed, not blocked');

        $decisions = ApprovalRequestStageDecision::where('approval_request_id', $request->id)->orderBy('level')->get();
        $this->assertFalse($decisions[0]->same_approver_as_prior_stage, 'no prior stage exists yet for level 1');
        $this->assertTrue($decisions[1]->same_approver_as_prior_stage, 'level 2 was cleared by the same person as level 1 — flagged for audit');
    }
}
