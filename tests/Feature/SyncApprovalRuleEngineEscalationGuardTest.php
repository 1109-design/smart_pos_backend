<?php

namespace Tests\Feature;

use App\Models\ApprovalDelegation;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security audit follow-up, same class as SyncAccountRoleMappingEscalationGuardTest
 * and SyncUserRoleEscalationGuardTest — this is the sync-side half of wiring
 * up approval_rule_sets/approval_rules/approval_delegations (see
 * app/Services/ApprovalRuleEngine.php and lib/core/auth/approval_rule_engine.dart).
 *
 * Two distinct fraud shapes covered here:
 *  - approval_rule_sets/approval_rules: an untrusted device weakening
 *    required_role/min_approvers/condition thresholds is equivalent to
 *    forging server-side sign-off for an entire process. Owner-only, same
 *    gate shape as account_role_mappings/role_permissions.
 *  - approval_delegations: naming ANY delegator_user_id and yourself as
 *    delegate hands you their approval authority outright. Allowed only
 *    when the acting user IS that delegator (self-service) or is the
 *    business owner (override) — not merely owner-gated, since routine
 *    self-delegation must keep working for every role.
 */
class SyncApprovalRuleEngineEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingDeviceToken(string $tenantId, User $user): string
    {
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

    private function push(string $token, array $record): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [$record]]);
    }

    private function ruleSetRecord(string $uuid, string $tenantId, bool $isEnabled = true): array
    {
        return [
            'table' => 'approval_rule_sets',
            'uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'process' => 'purchase_order',
                'name' => 'Purchase Order Approval',
                'is_enabled' => $isEnabled,
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public function test_a_cashier_cannot_create_an_approval_rule_set(): void
    {
        $tenantId = 'tenant-rule-set-cashier';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $uuid = (string) Str::uuid();
        $response = $this->push($token, $this->ruleSetRecord($uuid, $tenantId));

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'approval_rule_sets: only the business owner can manage approval rule sets.',
            $response->json('errors.0.reason'),
        );
        $this->assertNull(ApprovalRuleSet::find($uuid));
    }

    public function test_a_cashier_cannot_weaken_an_approval_rules_required_role(): void
    {
        $tenantId = 'tenant-rule-cashier';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $ruleSet = ApprovalRuleSet::create([
            'business_id' => $tenantId,
            'process' => 'purchase_order',
            'name' => 'Purchase Order Approval',
            'is_enabled' => true,
        ]);
        $rule = ApprovalRule::create([
            'business_id' => $tenantId,
            'rule_set_id' => $ruleSet->id,
            'level' => 1,
            'required_role' => 'business_owner',
        ]);

        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->push($token, [
            'table' => 'approval_rules',
            'uuid' => $rule->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'rule_set_id' => $ruleSet->id,
                'level' => 1,
                'required_role' => 'cashier',
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'approval_rules: only the business owner can manage approval rules.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame('business_owner', ApprovalRule::find($rule->id)->required_role);
    }

    public function test_the_business_owner_can_legitimately_create_a_rule_set_and_rule(): void
    {
        $tenantId = 'tenant-rule-set-owner';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $ruleSetUuid = (string) Str::uuid();
        $response = $this->push($token, $this->ruleSetRecord($ruleSetUuid, $tenantId));

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertNotNull(ApprovalRuleSet::find($ruleSetUuid));
    }

    public function test_a_device_can_self_delegate_its_own_approval_authority(): void
    {
        $tenantId = 'tenant-delegation-self';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $delegate = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-delegate@example.com']);
        $delegate->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $uuid = (string) Str::uuid();
        $response = $this->push($token, [
            'table' => 'approval_delegations',
            'uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'delegator_user_id' => $manager->id,
                'delegate_user_id' => $delegate->id,
                'reason' => 'On leave',
                'is_active' => true,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertNotNull(ApprovalDelegation::find($uuid));
    }

    public function test_a_device_cannot_forge_a_delegation_from_someone_else(): void
    {
        $tenantId = 'tenant-delegation-forged';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        // The cashier's own device claims the OWNER delegated approval
        // authority to the cashier — a self-granted privilege escalation
        // if this were ever allowed through.
        $uuid = (string) Str::uuid();
        $response = $this->push($token, [
            'table' => 'approval_delegations',
            'uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'delegator_user_id' => $owner->id,
                'delegate_user_id' => $cashier->id,
                'is_active' => true,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'approval_delegations: you may only create a delegation from your own account, or as the business owner.',
            $response->json('errors.0.reason'),
        );
        $this->assertNull(ApprovalDelegation::find($uuid));
    }

    public function test_the_business_owner_can_delegate_on_someone_elses_behalf(): void
    {
        $tenantId = 'tenant-delegation-override';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $manager = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $delegate = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-delegate@example.com']);
        $delegate->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $uuid = (string) Str::uuid();
        $response = $this->push($token, [
            'table' => 'approval_delegations',
            'uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $tenantId,
                'delegator_user_id' => $manager->id,
                'delegate_user_id' => $delegate->id,
                'is_active' => true,
            ],
            'updated_at' => now()->toIso8601String(),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertNotNull(ApprovalDelegation::find($uuid));
    }

    public function test_a_trusted_server_side_write_is_not_gated(): void
    {
        $tenantId = 'tenant-rule-set-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $uuid = (string) Str::uuid();
        app(SyncProcessor::class)->process(
            'approval_rule_sets',
            $uuid,
            'upsert',
            [
                'business_id' => $tenantId,
                'process' => 'purchase_order',
                'name' => 'Purchase Order Approval',
                'is_enabled' => true,
            ],
            trusted: true,
        );

        $this->assertNotNull(ApprovalRuleSet::find($uuid));
    }
}
