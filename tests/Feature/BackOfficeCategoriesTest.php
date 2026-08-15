<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId): User
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
                'role' => 'business_owner',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    public function test_update_and_toggle_active_cannot_touch_another_tenants_category(): void
    {
        $otherTenantId = 'tenant-office-cat-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignCategoryId = (string) Str::uuid();
        Category::create(['id' => $foreignCategoryId, 'business_id' => $otherTenantId, 'name' => 'Not Yours', 'is_active' => true]);

        $this->actingBackOfficeSession('tenant-office-cat-1');

        $this->put("/office/categories/{$foreignCategoryId}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->patch("/office/categories/{$foreignCategoryId}/toggle-active")
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $foreignCategoryId, 'name' => 'Not Yours', 'is_active' => true]);
    }

    public function test_update_and_toggle_active_work_for_the_owning_tenants_category(): void
    {
        $tenantId = 'tenant-office-cat-2';
        $this->actingBackOfficeSession($tenantId);

        $categoryId = (string) Str::uuid();
        Category::create(['id' => $categoryId, 'business_id' => $tenantId, 'name' => 'Beverages', 'is_active' => true]);

        $this->put("/office/categories/{$categoryId}", ['name' => 'Drinks'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'name' => 'Drinks']);

        $this->patch("/office/categories/{$categoryId}/toggle-active")->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $categoryId, 'is_active' => false]);
    }
}
