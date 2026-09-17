<?php

namespace Tests\Feature\Enterprise;

use App\Models\ApprovalDelegation;
use App\Models\ApprovalGroup;
use App\Models\ApprovalGroupMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\User;
use App\Services\ApprovalRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalRuleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ApprovalRuleEngine;
    }

    public function test_evaluates_amount_gt_condition_correctly()
    {
        $rule = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => Str::uuid(),
            'rule_set_id' => Str::uuid(), // dummy
            'condition_type' => 'amount_gt',
            'condition_value' => 1000,
        ]);

        $this->assertTrue($this->engine->evaluateCondition($rule, ['amount' => 1001]));
        $this->assertFalse($this->engine->evaluateCondition($rule, ['amount' => 999]));
        $this->assertFalse($this->engine->evaluateCondition($rule, ['amount' => 1000]));
    }

    public function test_evaluates_percentage_gt_condition_correctly()
    {
        $rule = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => Str::uuid(),
            'rule_set_id' => Str::uuid(),
            'condition_type' => 'percentage_gt',
            'condition_value' => 10,
        ]);

        $this->assertTrue($this->engine->evaluateCondition($rule, ['percentage' => 11]));
        $this->assertFalse($this->engine->evaluateCondition($rule, ['percentage' => 9]));
        $this->assertFalse($this->engine->evaluateCondition($rule, ['percentage' => 10]));
    }

    public function test_evaluates_always_condition_as_true()
    {
        $rule = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => Str::uuid(),
            'rule_set_id' => Str::uuid(),
            'condition_type' => 'always',
        ]);

        $this->assertTrue($this->engine->evaluateCondition($rule, []));
    }

    public function test_finds_applicable_rule_for_level()
    {
        $businessId = Str::uuid()->toString();
        $ruleSet = ApprovalRuleSet::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'test',
            'name' => 'test',
        ]);

        $rule1 = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $ruleSet->id,
            'level' => 1,
            'condition_type' => 'amount_lte',
            'condition_value' => 1000,
        ]);

        $rule2 = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $ruleSet->id,
            'level' => 2,
            'condition_type' => 'amount_gt',
            'condition_value' => 1000,
        ]);

        $foundRule = $this->engine->findApplicableRule($ruleSet, ['amount' => 500], 1);
        $this->assertNotNull($foundRule);
        $this->assertEquals($rule1->id, $foundRule->id);
    }

    public function test_resolves_rule_set_by_process()
    {
        $businessId = Str::uuid()->toString();

        $ruleSet = ApprovalRuleSet::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'purchase_order',
            'name' => 'PO',
            'is_enabled' => true,
        ]);

        $resolved = $this->engine->resolveRuleSet($businessId, 'purchase_order');
        $this->assertNotNull($resolved);
        $this->assertEquals($ruleSet->id, $resolved->id);

        $notResolved = $this->engine->resolveRuleSet($businessId, 'refund');
        $this->assertNull($notResolved);
    }

    public function test_separation_of_duties_blocks_self_approval()
    {
        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => Str::uuid(),
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => 'user-1',
            'status' => 'pending',
        ]);

        $this->assertFalse($this->engine->checkSeparationOfDuties($request, 'user-1'));
    }

    public function test_separation_of_duties_allows_different_approver()
    {
        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => Str::uuid(),
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => 'user-1',
            'status' => 'pending',
        ]);

        $this->assertTrue($this->engine->checkSeparationOfDuties($request, 'user-2'));
    }

    public function test_separation_of_duties_respects_require_different_user_false()
    {
        $businessId = Str::uuid()->toString();
        $ruleSetId = Str::uuid()->toString();

        ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $ruleSetId,
            'level' => 1,
            'require_different_user' => false,
        ]);

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => 'user-1',
            'status' => 'pending',
            'rule_set_id' => $ruleSetId,
            'current_level' => 1,
        ]);

        $this->assertTrue($this->engine->checkSeparationOfDuties($request, 'user-1'));
    }

    private function roleRule(string $businessId, string $requiredRole, int $level = 1): ApprovalRule
    {
        return ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => Str::uuid(), // dummy — no matching ApprovalRuleSet needed for these unit checks
            'level' => $level,
            'required_role' => $requiredRole,
        ]);
    }

    public function test_can_approve_checks_role_hierarchy()
    {
        $businessId = Str::uuid()->toString();

        $role = Role::create(['name' => 'branch_manager']);

        $user = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $user->assignRole('branch_manager');

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => Str::uuid(), // different user
            'status' => 'pending',
        ]);

        $this->assertTrue($this->engine->canApprove($businessId, $user->id, $request, $this->roleRule($businessId, 'sales_supervisor')));
        $this->assertTrue($this->engine->canApprove($businessId, $user->id, $request, $this->roleRule($businessId, 'finance_manager')));
    }

    public function test_can_approve_blocks_lower_role()
    {
        $businessId = Str::uuid()->toString();

        $role = Role::create(['name' => 'cashier']);

        $user = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $user->assignRole('cashier');

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => Str::uuid(),
            'status' => 'pending',
        ]);

        $this->assertFalse($this->engine->canApprove($businessId, $user->id, $request, $this->roleRule($businessId, 'branch_manager')));
    }

    public function test_can_approve_with_active_delegation()
    {
        $businessId = Str::uuid()->toString();

        $roleCashier = Role::create(['name' => 'cashier']);
        $roleManager = Role::create(['name' => 'branch_manager']);

        $cashier = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $cashier->assignRole('cashier');

        $manager = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $manager->assignRole('branch_manager');

        ApprovalDelegation::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'delegator_user_id' => $manager->id,
            'delegate_user_id' => $cashier->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => Str::uuid(),
            'status' => 'pending',
        ]);

        $this->assertTrue($this->engine->canApprove($businessId, $cashier->id, $request, $this->roleRule($businessId, 'branch_manager')));
    }

    public function test_can_approve_blocks_expired_delegation()
    {
        $businessId = Str::uuid()->toString();

        // Roles might already exist if tests run in same DB transaction but let's safely handle it using firstOrCreate
        $roleCashier = Role::firstOrCreate(['name' => 'cashier']);
        $roleManager = Role::firstOrCreate(['name' => 'branch_manager']);

        $cashier = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $cashier->assignRole('cashier');

        $manager = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $manager->assignRole('branch_manager');

        ApprovalDelegation::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'delegator_user_id' => $manager->id,
            'delegate_user_id' => $cashier->id,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => Str::uuid(),
            'status' => 'pending',
        ]);

        $this->assertFalse($this->engine->canApprove($businessId, $cashier->id, $request, $this->roleRule($businessId, 'branch_manager')));
    }

    public function test_can_approve_blocks_self_even_with_right_role()
    {
        $businessId = Str::uuid()->toString();

        $roleManager = Role::firstOrCreate(['name' => 'branch_manager']);

        $manager = User::factory()->create([
            'business_id' => $businessId,
        ]);
        $manager->assignRole('branch_manager');

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => $manager->id, // Self!
            'status' => 'pending',
        ]);

        $this->assertFalse($this->engine->canApprove($businessId, $manager->id, $request, $this->roleRule($businessId, 'sales_supervisor')));
    }

    public function test_can_approve_checks_group_membership()
    {
        $businessId = Str::uuid()->toString();
        $groupId = (string) Str::uuid();

        $member = User::factory()->create(['business_id' => $businessId]);
        $nonMember = User::factory()->create(['business_id' => $businessId]);

        ApprovalGroup::forceCreate(['id' => $groupId, 'business_id' => $businessId, 'name' => 'Regional Managers']);
        ApprovalGroupMember::forceCreate(['id' => Str::uuid(), 'business_id' => $businessId, 'group_id' => $groupId, 'user_id' => $member->id]);

        $rule = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => Str::uuid(),
            'level' => 1,
            'approval_group_id' => $groupId,
        ]);

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'test',
            'requested_by_user_id' => Str::uuid(),
            'status' => 'pending',
        ]);

        $this->assertTrue($this->engine->canApprove($businessId, $member->id, $request, $rule));
        $this->assertFalse($this->engine->canApprove($businessId, $nonMember->id, $request, $rule));
    }

    public function test_can_approve_via_delegation_into_a_group()
    {
        $businessId = Str::uuid()->toString();
        $groupId = (string) Str::uuid();

        $groupMember = User::factory()->create(['business_id' => $businessId]);
        $delegate = User::factory()->create(['business_id' => $businessId]);

        ApprovalGroup::forceCreate(['id' => $groupId, 'business_id' => $businessId, 'name' => 'Regional Managers']);
        ApprovalGroupMember::forceCreate(['id' => Str::uuid(), 'business_id' => $businessId, 'group_id' => $groupId, 'user_id' => $groupMember->id]);

        ApprovalDelegation::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'delegator_user_id' => $groupMember->id,
            'delegate_user_id' => $delegate->id,
            'process' => 'refund',
            'level' => 1,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        $ruleSet = ApprovalRuleSet::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'process' => 'refund',
            'name' => 'Refund',
        ]);
        $rule = ApprovalRule::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'rule_set_id' => $ruleSet->id,
            'level' => 1,
            'approval_group_id' => $groupId,
        ]);

        $request = ApprovalRequest::forceCreate([
            'id' => Str::uuid(),
            'business_id' => $businessId,
            'subject_type' => 'test',
            'subject_id' => 'test',
            'action' => 'refund',
            'requested_by_user_id' => Str::uuid(),
            'status' => 'pending',
        ]);

        $this->assertTrue($this->engine->canApprove($businessId, $delegate->id, $request, $rule));
        $this->assertSame($groupMember->id, $this->engine->resolveDelegationSource($businessId, $delegate->id, $rule));
        $this->assertNull($this->engine->resolveDelegationSource($businessId, $groupMember->id, $rule));
    }
}
