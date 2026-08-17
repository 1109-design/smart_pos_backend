<?php

namespace Tests\Feature;

use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers creating staff users from the web Back Office — previously the
 * only way to add a user was standing at a till (Settings → Users & PINs).
 */
class BackOfficeUserCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{tenant_id: string, session: array} */
    private function ownerSession(string $tenantId): array
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $owner = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');

        return [
            'tenant_id' => $tenantId,
            'session' => [
                'backoffice' => [
                    'tenant_id' => $tenantId,
                    'user_id' => $owner->id,
                    'user_name' => $owner->name,
                    'user_email' => $owner->email,
                    'role' => 'business_owner',
                    'business_name' => $tenantId,
                    'currency_code' => 'USD',
                ],
            ],
        ];
    }

    public function test_owner_can_create_a_user_from_the_web_portal(): void
    {
        $ctx = $this->ownerSession('tenant-bo-create-1');

        $response = $this->withSession($ctx['session'])->post('/office/users', [
            'name' => 'New Cashier',
            'email' => 'new-cashier@example.com',
            'role' => 'cashier',
            'pin' => '4321',
        ]);

        $response->assertRedirect(route('office.users.index'));

        $created = User::where('email', 'new-cashier@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('tenant-bo-create-1', $created->business_id);
        $this->assertSame('cashier', $created->roles->first()?->name);
        $this->assertTrue((bool) $created->is_active);

        // PIN is bcrypt-hashed, never stored raw.
        $this->assertTrue(Hash::isHashed($created->pin_hash));
        $this->assertTrue(Hash::check('4321', $created->pin_hash));

        // A device-facing sync record was published so every till picks
        // this user up on its next pull, same as one created at the till.
        $this->assertDatabaseHas('sync_records', [
            'business_id' => 'tenant-bo-create-1',
            'table_name' => 'users',
            'record_uuid' => $created->id,
        ]);
        $record = SyncRecord::where('record_uuid', $created->id)->first();
        $this->assertNull($record->device_id);
    }

    public function test_new_user_gets_no_usable_backoffice_password_until_one_is_set_deliberately(): void
    {
        $ctx = $this->ownerSession('tenant-bo-create-2');

        $this->withSession($ctx['session'])->post('/office/users', [
            'name' => 'New Manager',
            'email' => 'new-manager@example.com',
            'role' => 'manager',
            'pin' => '1111',
        ])->assertRedirect();

        $created = User::where('email', 'new-manager@example.com')->first();
        $this->assertFalse(Hash::check('', $created->password));
        $this->assertFalse(Hash::check('password', $created->password));
    }

    public function test_email_must_be_unique(): void
    {
        $ctx = $this->ownerSession('tenant-bo-create-3');
        User::factory()->create(['business_id' => $ctx['tenant_id'], 'email' => 'taken@example.com']);

        $response = $this->withSession($ctx['session'])->post('/office/users', [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'role' => 'cashier',
            'pin' => '2222',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_cashier_cannot_create_a_user(): void
    {
        $tenantId = 'tenant-bo-create-4';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $cashier = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');

        $response = $this->withSession([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $cashier->id,
                'user_name' => $cashier->name,
                'user_email' => $cashier->email,
                'role' => 'cashier',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ])->post('/office/users', [
            'name' => 'Nope',
            'email' => (string) Str::uuid().'@example.com',
            'role' => 'cashier',
            'pin' => '3333',
        ]);

        $response->assertForbidden();
    }
}
