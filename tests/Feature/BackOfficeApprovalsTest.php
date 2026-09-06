<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ApprovalRequest;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
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
                'role' => $role,
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    public function test_a_pending_request_can_be_approved_from_back_office(): void
    {
        $tenantId = 'tenant-approvals-1';
        $manager = $this->actingBackOfficeSession($tenantId);

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'Transaction',
            (string) Str::uuid(),
            'void_transaction',
            (string) Str::uuid(),
            ['reason' => 'Customer changed their mind'],
        );

        $this->assertSame('pending', $request->status);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'approval_requests', 'record_uuid' => $request->id]);

        $this->post("/office/approvals/{$request->id}/approve", ['reason' => 'Confirmed with customer'])
            ->assertRedirect();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($manager->id, $request->approver_user_id);
        $this->assertSame('Confirmed with customer', $request->reason);
        $this->assertNotNull($request->approved_at);
        // The context payload the till attached survives the resolve round-trip.
        $this->assertSame('Customer changed their mind', $request->payload_json['reason']);
    }

    public function test_approving_a_queued_exchange_rate_change_writes_the_real_rate(): void
    {
        $tenantId = 'tenant-approvals-rate-1';
        $manager = $this->actingBackOfficeSession($tenantId);
        $rateId = (string) Str::uuid();

        $request = app(ApprovalService::class)->request(
            $tenantId,
            'ExchangeRate',
            $rateId,
            'change_exchange_rate',
            (string) Str::uuid(),
            ['from_currency' => 'ZWG', 'to_currency' => 'USD', 'rate' => 26.5, 'locked' => false],
        );

        $this->post("/office/approvals/{$request->id}/approve")->assertRedirect();

        $rate = ExchangeRate::findOrFail($rateId);
        $this->assertSame($tenantId, $rate->business_id);
        $this->assertSame('ZWG', $rate->from_currency);
        $this->assertSame('USD', $rate->to_currency);
        $this->assertSame('26.50000000', $rate->rate);
        $this->assertSame($manager->id, $rate->set_by_user_id);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'exchange_rates', 'record_uuid' => $rateId]);
    }

    public function test_rejecting_a_queued_exchange_rate_change_does_not_write_a_rate(): void
    {
        $tenantId = 'tenant-approvals-rate-2';
        $this->actingBackOfficeSession($tenantId);
        $rateId = (string) Str::uuid();

        $request = app(ApprovalService::class)->request(
            $tenantId, 'ExchangeRate', $rateId, 'change_exchange_rate', (string) Str::uuid(),
            ['from_currency' => 'ZWG', 'to_currency' => 'USD', 'rate' => 26.5],
        );

        $this->post("/office/approvals/{$request->id}/reject")->assertRedirect();

        $this->assertDatabaseMissing('exchange_rates', ['id' => $rateId]);
    }

    public function test_a_pending_request_can_be_rejected(): void
    {
        $tenantId = 'tenant-approvals-2';
        $this->actingBackOfficeSession($tenantId);

        $request = app(ApprovalService::class)->request(
            $tenantId, 'ExchangeRate', (string) Str::uuid(), 'change_exchange_rate', (string) Str::uuid(),
        );

        $this->post("/office/approvals/{$request->id}/reject", ['reason' => 'Rate looks wrong'])
            ->assertRedirect();

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_resolving_an_already_resolved_request_fails_gracefully(): void
    {
        $tenantId = 'tenant-approvals-3';
        $this->actingBackOfficeSession($tenantId);

        $request = app(ApprovalService::class)->request(
            $tenantId, 'Transaction', (string) Str::uuid(), 'void_transaction', (string) Str::uuid(),
        );

        $this->post("/office/approvals/{$request->id}/approve")->assertRedirect();
        $this->post("/office/approvals/{$request->id}/approve")->assertSessionHasErrors('approval');
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_manager_without_the_permission_cannot_resolve_requests(): void
    {
        $tenantId = 'tenant-approvals-4';
        $this->actingBackOfficeSession($tenantId, role: 'cashier');

        $request = app(ApprovalService::class)->request(
            $tenantId, 'Transaction', (string) Str::uuid(), 'void_transaction', (string) Str::uuid(),
        );

        $this->get('/office/approvals')->assertForbidden();
        $this->post("/office/approvals/{$request->id}/approve")->assertForbidden();
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_approvals_are_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-approvals-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'ZZZZZZ']);

        $foreignRequest = ApprovalRequest::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'subject_type' => 'Transaction',
            'subject_id' => (string) Str::uuid(),
            'action' => 'void_transaction',
            'requested_by_user_id' => (string) Str::uuid(),
            'status' => 'pending',
        ]);

        $tenantId = 'tenant-approvals-5';
        $this->actingBackOfficeSession($tenantId);

        $this->post("/office/approvals/{$foreignRequest->id}/approve")->assertNotFound();
        $this->assertSame('pending', $foreignRequest->fresh()->status);
    }
}
