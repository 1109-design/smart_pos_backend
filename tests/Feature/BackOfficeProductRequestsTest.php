<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ProductRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeProductRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'cashier'): User
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

    public function test_any_backoffice_role_can_log_and_view_a_request(): void
    {
        $tenantId = 'tenant-requests-1';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->post('/office/product-requests', [
            'product_name' => 'Oat Milk 1L',
            'note' => 'Asked twice this week',
        ])->assertRedirect();

        $request = ProductRequest::where('business_id', $tenantId)->first();
        $this->assertNotNull($request);
        $this->assertSame('Oat Milk 1L', $request->product_name);
        $this->assertSame('open', $request->status);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'product_requests', 'record_uuid' => $request->id]);

        $this->get('/office/product-requests')->assertOk();
    }

    public function test_toggle_status_flips_between_open_and_fulfilled(): void
    {
        $tenantId = 'tenant-requests-2';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/product-requests', ['product_name' => 'Peanut Butter 500g'])->assertRedirect();
        $request = ProductRequest::where('business_id', $tenantId)->first();

        $this->patch("/office/product-requests/{$request->id}/toggle-status")->assertRedirect();
        $this->assertSame('fulfilled', $request->fresh()->status);

        $this->patch("/office/product-requests/{$request->id}/toggle-status")->assertRedirect();
        $this->assertSame('open', $request->fresh()->status);
    }

    public function test_destroy_soft_deletes_the_request(): void
    {
        $tenantId = 'tenant-requests-3';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/product-requests', ['product_name' => 'Almond Flour'])->assertRedirect();
        $request = ProductRequest::where('business_id', $tenantId)->first();

        $this->delete("/office/product-requests/{$request->id}")->assertRedirect();
        $this->assertNotNull($request->fresh()->deleted_at);
    }

    public function test_requests_are_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-requests-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'ZZZZZZ']);
        $foreignRequest = ProductRequest::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'product_name' => 'Their Product',
            'status' => 'open',
            'created_at' => now(),
        ]);

        $tenantId = 'tenant-requests-4';
        $this->actingBackOfficeSession($tenantId);

        $this->patch("/office/product-requests/{$foreignRequest->id}/toggle-status")->assertNotFound();
        $this->delete("/office/product-requests/{$foreignRequest->id}")->assertNotFound();
        $this->assertSame('open', $foreignRequest->fresh()->status);
        $this->assertNull($foreignRequest->fresh()->deleted_at);
    }
}
