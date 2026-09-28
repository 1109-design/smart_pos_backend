<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\TillLocationAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeTillsTest extends TestCase
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

    public function test_owner_can_reassign_a_till_to_a_different_location(): void
    {
        $tenantId = 'tenant-till-reassign-1';
        $owner = $this->actingBackOfficeSession($tenantId);

        $originalLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $newLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $originalLocation->id,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->put("/office/tills/{$till->id}/location", ['location_id' => $newLocation->id]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $till->refresh();
        $this->assertSame($newLocation->id, $till->location_id);

        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'tills',
            'record_uuid' => $till->id,
        ]);

        $audit = TillLocationAudit::where('till_id', $till->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($originalLocation->id, $audit->from_location_id);
        $this->assertSame($newLocation->id, $audit->to_location_id);
        $this->assertSame($owner->id, $audit->changed_by_user_id);
    }

    /**
     * Regression: the audit trail used to be two mutable columns on Till
     * itself, which could only ever remember the single most recent move.
     * Moving to an append-only log means a till's full reassignment history
     * survives more than one move, and the list page surfaces the latest one.
     */
    public function test_tills_list_shows_the_most_recent_reassignment(): void
    {
        $tenantId = 'tenant-till-reassign-history';
        $owner = $this->actingBackOfficeSession($tenantId);

        $branchA = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $branchC = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch C', 'type' => 'shop', 'is_active' => true]);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $branchA->id,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $this->put("/office/tills/{$till->id}/location", ['location_id' => $branchB->id])->assertRedirect();
        $this->put("/office/tills/{$till->id}/location", ['location_id' => $branchC->id])->assertRedirect();

        $this->assertSame(2, TillLocationAudit::where('till_id', $till->id)->count());

        $response = $this->get('/office/tills');
        $response->assertInertia(fn ($page) => $page
            ->where('tills.0.last_moved_by_user_name', $owner->name)
            ->where('tills.0.location_id', $branchC->id)
        );
    }

    public function test_reassignment_is_blocked_while_the_till_has_an_open_shift(): void
    {
        $tenantId = 'tenant-till-reassign-2';
        $this->actingBackOfficeSession($tenantId);

        $originalLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $newLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $originalLocation->id,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);
        Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $originalLocation->id,
            'till_id' => $till->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        $response = $this->put("/office/tills/{$till->id}/location", ['location_id' => $newLocation->id]);

        $response->assertSessionHasErrors('location_id');
        $this->assertSame($originalLocation->id, $till->fresh()->location_id);
    }

    public function test_cashier_cannot_reassign_a_till(): void
    {
        $tenantId = 'tenant-till-reassign-3';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $originalLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $newLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $till = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $originalLocation->id,
            'name' => 'Till 1',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->put("/office/tills/{$till->id}/location", ['location_id' => $newLocation->id]);

        $response->assertForbidden();
        $this->assertSame($originalLocation->id, $till->fresh()->location_id);
    }

    public function test_cannot_reassign_a_till_belonging_to_another_business(): void
    {
        $tenantId = 'tenant-till-reassign-4';
        $this->actingBackOfficeSession($tenantId);

        $otherTenantId = 'tenant-till-reassign-4-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $otherLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Other Branch', 'type' => 'shop', 'is_active' => true]);
        $foreignTill = Till::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'location_id' => $otherLocation->id,
            'name' => 'Foreign Till',
            'register_number' => 1,
            'is_active' => true,
        ]);

        $response = $this->put("/office/tills/{$foreignTill->id}/location", ['location_id' => $otherLocation->id]);

        $response->assertNotFound();
    }

    /**
     * Regression: TillsController::index() had no authorization check at
     * all — any authenticated backoffice session, including a cashier,
     * could list every till, its device_id, and its location business-wide.
     */
    public function test_cashier_cannot_view_the_tills_list(): void
    {
        $tenantId = 'tenant-till-index-1';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/tills')->assertForbidden();
    }

    public function test_owner_can_view_the_tills_list(): void
    {
        $tenantId = 'tenant-till-index-2';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id, 'name' => 'Till 1', 'register_number' => 1, 'is_active' => true]);

        $this->get('/office/tills')->assertOk();
    }

    public function test_a_scoped_manager_only_sees_tills_at_their_own_locations(): void
    {
        $tenantId = 'tenant-till-index-3';
        $manager = $this->actingBackOfficeSession($tenantId, 'manager');

        $ownLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Own Branch', 'type' => 'shop', 'is_active' => true]);
        $otherLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Other Branch', 'type' => 'shop', 'is_active' => true]);
        $manager->locations()->attach($ownLocation->id);

        $ownTill = Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $ownLocation->id, 'name' => 'Own Till', 'register_number' => 1, 'is_active' => true]);
        Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $otherLocation->id, 'name' => 'Other Till', 'register_number' => 1, 'is_active' => true]);

        $response = $this->get('/office/tills');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('tills', 1)
            ->where('tills.0.id', $ownTill->id)
        );
    }

    /**
     * Regression: reassignLocation() checked permission and tenant
     * ownership but never the acting user's location scope, so a manager
     * restricted to Branch A could move a till belonging to Branch B into
     * Branch C — entirely outside their granted visibility.
     */
    public function test_a_scoped_manager_cannot_move_a_till_between_locations_outside_their_scope(): void
    {
        $tenantId = 'tenant-till-scope-move-1';
        $manager = $this->actingBackOfficeSession($tenantId, 'manager');

        $ownLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Own Branch', 'type' => 'shop', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $branchC = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch C', 'type' => 'shop', 'is_active' => true]);
        $manager->locations()->attach($ownLocation->id);

        $till = Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchB->id, 'name' => 'Till 1', 'register_number' => 1, 'is_active' => true]);

        $response = $this->put("/office/tills/{$till->id}/location", ['location_id' => $branchC->id]);

        $response->assertForbidden();
        $this->assertSame($branchB->id, $till->fresh()->location_id);
    }

    public function test_a_scoped_manager_can_move_a_till_between_their_own_locations(): void
    {
        $tenantId = 'tenant-till-scope-move-2';
        $manager = $this->actingBackOfficeSession($tenantId, 'manager');

        $branchA = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch A', 'type' => 'shop', 'is_active' => true]);
        $branchB = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Branch B', 'type' => 'shop', 'is_active' => true]);
        $manager->locations()->attach([$branchA->id, $branchB->id]);

        $till = Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $branchA->id, 'name' => 'Till 1', 'register_number' => 1, 'is_active' => true]);

        $response = $this->put("/office/tills/{$till->id}/location", ['location_id' => $branchB->id]);

        $response->assertRedirect();
        $this->assertSame($branchB->id, $till->fresh()->location_id);
    }
}
