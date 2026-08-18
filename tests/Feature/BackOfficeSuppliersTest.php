<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeSuppliersTest extends TestCase
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

    public function test_create_update_and_archive_a_supplier(): void
    {
        $tenantId = 'tenant-suppliers-1';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/suppliers', [
            'name' => 'Delta Beverages',
            'contact_name' => 'Tino',
            'phone' => '0771234567',
        ])->assertRedirect();

        $supplier = Supplier::where('business_id', $tenantId)->first();
        $this->assertNotNull($supplier);
        $this->assertSame('Delta Beverages', $supplier->name);
        $this->assertTrue($supplier->is_active);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'suppliers', 'record_uuid' => $supplier->id]);

        $this->put("/office/suppliers/{$supplier->id}", [
            'name' => 'Delta Beverages Pvt Ltd',
        ])->assertRedirect();
        $this->assertSame('Delta Beverages Pvt Ltd', $supplier->fresh()->name);

        $this->patch("/office/suppliers/{$supplier->id}/toggle-active")->assertRedirect();
        $this->assertFalse($supplier->fresh()->is_active);

        $this->patch("/office/suppliers/{$supplier->id}/toggle-active")->assertRedirect();
        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_cashier_cannot_manage_suppliers(): void
    {
        $tenantId = 'tenant-suppliers-2';
        $this->actingBackOfficeSession($tenantId, 'cashier');

        $this->get('/office/suppliers')->assertForbidden();
        $this->post('/office/suppliers', ['name' => 'Nope Ltd'])->assertForbidden();
    }

    public function test_suppliers_are_scoped_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-suppliers-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'ZZZZZZ']);
        $foreignSupplier = Supplier::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'name' => 'Their Supplier',
            'is_active' => true,
        ]);

        $tenantId = 'tenant-suppliers-3';
        $this->actingBackOfficeSession($tenantId);

        $this->put("/office/suppliers/{$foreignSupplier->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->patch("/office/suppliers/{$foreignSupplier->id}/toggle-active")->assertNotFound();
        $this->assertSame('Their Supplier', $foreignSupplier->fresh()->name);
        $this->assertTrue($foreignSupplier->fresh()->is_active);
    }
}
