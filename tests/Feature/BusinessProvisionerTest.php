<?php

namespace Tests\Feature;

use App\Models\Accounting\GlAccount;
use App\Models\User;
use App\Services\BusinessProvisioner;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles/permissions are seeded once globally at deploy time in the
        // single-database architecture; the provisioner assumes they exist.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function provision(array $overrides = [])
    {
        return app(BusinessProvisioner::class)->provision(array_merge([
            'business_name' => 'Acme Retail',
            'owner_email' => 'owner@acme.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->addMonth(),
            'currency_code' => 'USD',
            'admin_name' => 'Ada Owner',
            'admin_pin' => '4321',
        ], $overrides));
    }

    public function test_provision_creates_tenant_domain_and_scoped_owner_with_role(): void
    {
        $tenant = $this->provision();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'business_name' => 'Acme Retail',
            'tier' => 'pro',
        ]);
        $this->assertNotEmpty($tenant->pairing_code);
        $this->assertDatabaseHas('domains', ['tenant_id' => $tenant->id]);

        $tenant->run(function () use ($tenant) {
            $owner = User::first();

            $this->assertNotNull($owner);
            $this->assertSame($tenant->id, $owner->business_id);
            $this->assertSame('owner@acme.com', $owner->email);
            $this->assertTrue($owner->hasRole('business_owner'));
            $this->assertTrue(Hash::check('4321', $owner->pin_hash));
        });
    }

    public function test_provision_seeds_a_chart_of_accounts_for_the_new_business(): void
    {
        $tenant = $this->provision();

        $this->assertGreaterThan(0, GlAccount::where('business_id', $tenant->id)->count());
        $this->assertDatabaseHas('gl_accounts', ['business_id' => $tenant->id, 'code' => '1000', 'name' => 'Cash']);
    }

    public function test_duplicate_business_names_receive_unique_domains(): void
    {
        $first = $this->provision(['business_name' => 'Joes Shop', 'owner_email' => 'a@x.com']);
        $second = $this->provision(['business_name' => 'Joes Shop', 'owner_email' => 'b@x.com']);

        $domainA = DB::table('domains')->where('tenant_id', $first->id)->value('domain');
        $domainB = DB::table('domains')->where('tenant_id', $second->id)->value('domain');

        $this->assertNotSame($domainA, $domainB);
    }
}
