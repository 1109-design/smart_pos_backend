<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enterprise roles/permissions/approval-workflow audit follow-up (master
 * spec section 16, "Separation of Duties" — mandatory for an enterprise
 * ERP): the person who creates a sensitive transaction must not
 * automatically be able to approve it themselves.
 *
 * ApprovalRuleEngine::canApprove()/checkSeparationOfDuties() already
 * existed and were unit-tested in isolation (ApprovalRuleEngineTest), but
 * nothing called them from the real decision path —
 * ApprovalsController::approve()/reject() only checked the coarse
 * MANAGE_APPROVALS permission ("can this person decide approvals at all"),
 * never whether *this* person is an eligible decider for *this specific*
 * request. Any manager with that permission could resolve a request they
 * themselves raised. Wired ApprovalRuleEngine::canApprove() into
 * ApprovalService::resolve() to close that.
 */
class ApprovalSeparationOfDutiesTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, User $user, string $role = 'business_owner'): void
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $role,
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);
    }

    public function test_a_manager_cannot_approve_their_own_request(): void
    {
        $tenantId = 'tenant-sod-1';
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $manager = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com', 'is_active' => true]);
        $this->actingBackOfficeSession($tenantId, $manager);

        // The manager raises their own request (e.g. queuing a void with no
        // one else on shift), then tries to also be the one who clears it.
        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            $manager->id,
            ['reason' => 'Self-raised while alone on shift'],
        );

        $response = $this->post("/office/approvals/{$request->id}/approve");

        $response->assertRedirect();
        $response->assertSessionHasErrors('approval');
        $this->assertTrue($request->fresh()->isPending());
    }

    public function test_a_different_manager_can_approve_it(): void
    {
        $tenantId = 'tenant-sod-2';
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $requester = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-requester@example.com', 'is_active' => true]);
        $approver = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-approver@example.com', 'is_active' => true]);

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            $requester->id,
            ['reason' => 'Customer changed their mind'],
        );

        $this->actingBackOfficeSession($tenantId, $approver);
        $response = $this->post("/office/approvals/{$request->id}/approve");

        $response->assertRedirect();
        $response->assertSessionMissing('errors');
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($approver->id, $request->fresh()->approver_user_id);
    }

    public function test_a_manager_cannot_reject_their_own_request_either(): void
    {
        $tenantId = 'tenant-sod-3';
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $manager = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-manager@example.com', 'is_active' => true]);
        $this->actingBackOfficeSession($tenantId, $manager);

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            $manager->id,
            ['reason' => 'Self-raised'],
        );

        $response = $this->post("/office/approvals/{$request->id}/reject", ['reason' => 'changed my mind']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('approval');
        $this->assertTrue($request->fresh()->isPending());
    }
}
