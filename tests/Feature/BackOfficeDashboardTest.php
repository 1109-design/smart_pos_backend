<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The BackOffice auth middleware calls tenancy()->initialize(), which
     * re-points the central DB connection in a way that clashes with
     * RefreshDatabase's in-memory test database. The controller under test is
     * single-DB and only reads the backoffice session, so bypass the
     * middleware and set the session directly.
     */
    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => '123456']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-owner@example.com',
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

    public function test_dashboard_stats_exclude_other_businesses(): void
    {
        $tenantId = 'tenant-dash-mine';
        $user = $this->actingBackOfficeSession($tenantId);

        Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'user_id' => $user->id,
            'subtotal' => 10,
            'total' => 10,
            'tax_total' => 0,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202608-MINE-1',
            'created_at' => now(),
        ]);
        Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'My Customer']);

        $otherTenantId = 'tenant-dash-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => '654321']);
        $otherUser = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId]);

        Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'user_id' => $otherUser->id,
            'subtotal' => 5000,
            'total' => 5000,
            'tax_total' => 0,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202608-OTHER-1',
            'created_at' => now(),
        ]);
        Customer::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Other Customer']);

        $response = $this->get('/office/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/Dashboard')
            ->where('stats.today_revenue', fn ($value) => (float) $value === 10.0)
            ->where('stats.today_count', fn ($value) => (int) $value === 1)
            ->where('stats.month_revenue', fn ($value) => (float) $value === 10.0)
            ->where('stats.customer_count', fn ($value) => (int) $value === 1)
            ->has('recent_transactions', 1)
            ->where('recent_transactions.0.sale_number', '202608-MINE-1')
        );
    }
}
