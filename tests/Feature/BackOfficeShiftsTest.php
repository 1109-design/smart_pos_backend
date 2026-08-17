<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeShiftsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * See BackOfficeTransactionsTest for why the middleware is bypassed here.
     */
    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => '123456']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'email' => 'office-owner@example.com',
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

    public function test_open_shift_appears_in_the_live_list(): void
    {
        $tenantId = 'tenant-office-shift-1';
        $user = $this->actingBackOfficeSession($tenantId);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'cashier_id' => $user->id,
            'opened_at' => now()->subHour(),
            'status' => 'open',
            'opening_float' => 50,
            'total_sales' => 120.50,
            'transaction_count' => 4,
        ]);

        $response = $this->get('/office/shifts');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/Shifts')
            ->has('open_shifts', 1)
            ->where('open_shifts.0.cashier_name', $user->name)
            ->where('open_shifts.0.transaction_count', 4)
        );
    }

    public function test_status_filter_narrows_shift_history(): void
    {
        $tenantId = 'tenant-office-shift-2';
        $user = $this->actingBackOfficeSession($tenantId);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'cashier_id' => $user->id,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
            'status' => 'closed',
            'opening_float' => 50,
            'counted_cash' => 200,
            'expected_cash' => 210,
            'variance' => -10,
            'total_sales' => 500,
        ]);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'cashier_id' => $user->id,
            'opened_at' => now()->subHour(),
            'status' => 'open',
            'opening_float' => 50,
        ]);

        $response = $this->get('/office/shifts?status=closed');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('shifts.data', 1)
            ->where('shifts.data.0.status', 'closed')
            ->where('shifts.data.0.variance', fn ($value) => (float) $value === -10.0)
        );
    }

    public function test_shifts_are_scoped_and_summarised_per_location(): void
    {
        $tenantId = 'tenant-office-shift-locations';
        $user = $this->actingBackOfficeSession($tenantId);

        $downtown = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Downtown']);
        $mall = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Mall']);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $downtown->id,
            'cashier_id' => $user->id,
            'opened_at' => now()->subHour(),
            'status' => 'open',
            'opening_float' => 50,
            'total_sales' => 300,
        ]);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $mall->id,
            'cashier_id' => $user->id,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(6),
            'status' => 'closed',
            'opening_float' => 50,
            'total_sales' => 150,
            'variance' => 5,
        ]);

        // Unfiltered: both locations appear live and in the breakdown.
        $response = $this->get('/office/shifts');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('open_shifts', 1)
            ->where('open_shifts.0.location_name', 'Downtown')
            ->has('by_location', 2)
            ->has('locations', 2)
        );

        // Filtered to one location: only that location's shift comes through.
        $response = $this->get('/office/shifts?location='.$mall->id);
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('open_shifts', 0)
            ->has('shifts.data', 1)
            ->where('shifts.data.0.location_name', 'Mall')
            ->where('summary.total_shifts', fn ($value) => (int) $value === 1)
        );
    }

    public function test_shifts_from_other_businesses_are_not_shown(): void
    {
        $tenantId = 'tenant-office-shift-mine';
        $user = $this->actingBackOfficeSession($tenantId);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'cashier_id' => $user->id,
            'opened_at' => now()->subHour(),
            'status' => 'open',
            'opening_float' => 50,
        ]);

        $otherTenantId = 'tenant-office-shift-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => '654321']);
        $otherUser = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId]);

        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'cashier_id' => $otherUser->id,
            'opened_at' => now()->subHour(),
            'status' => 'open',
            'opening_float' => 999,
        ]);

        $response = $this->get('/office/shifts');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('open_shifts', 1)
            ->where('open_shifts.0.opening_float', fn ($value) => (float) $value === 50.0)
            ->where('summary.total_shifts', fn ($value) => (int) $value === 1)
        );
    }
}
