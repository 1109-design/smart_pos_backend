<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeReportsTest extends TestCase
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

    public function test_reports_summary_excludes_other_businesses(): void
    {
        $tenantId = 'tenant-reports-mine';
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

        $otherTenantId = 'tenant-reports-other';
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

        $response = $this->get('/office/reports');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/Reports')
            ->where('summary.total_transactions', fn ($value) => (int) $value === 1)
            ->where('summary.gross_revenue', fn ($value) => (float) $value === 10.0)
        );
    }
}
