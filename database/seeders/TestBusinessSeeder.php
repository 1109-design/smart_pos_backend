<?php

namespace Database\Seeders;

use App\Models\ActivationCode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestBusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Create Tenant
        $tenant = Tenant::firstOrCreate(
            ['id' => 'test-business'],
            [
                'business_name' => 'Test Business',
                'owner_email' => 'test@example.com',
                'tier' => 'pro',
                'pairing_code' => 'TEST66',
                'is_active' => true,
                'country' => 'ZW',
                'currency_code' => 'USD',
            ]
        );

        $tenant->domains()->firstOrCreate(['domain' => 'test-business.localhost']);

        // Create Tenant User
        tenancy()->initialize($tenant);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->call(RolesAndPermissionsSeeder::class);

        $user = User::on('tenant')->create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'pin_hash' => Hash::make('1234'),
            'is_active' => true,
        ]);

        $user->assignRole('manager');

        // Create Sync Record for the user
        \App\Models\SyncRecord::create([
            'table_name'  => 'users',
            'record_uuid' => $user->id,
            'operation'   => 'upsert',
            'payload'     => array_merge($user->toArray(), ['role' => 'manager']),
            'synced_at'   => now(),
        ]);

        // Create Business Profile in Tenant DB
        $business = \App\Models\Business::create([
            'id'            => $tenant->id,
            'name'          => $tenant->business_name,
            'email'         => $tenant->owner_email,
            'currency_code' => $tenant->currency_code,
            'country'       => $tenant->country,
        ]);

        // Create Sync Record for the business profile
        \App\Models\SyncRecord::create([
            'table_name'  => 'businesses',
            'record_uuid' => $business->id,
            'operation'   => 'upsert',
            'payload'     => $business->toArray(),
            'synced_at'   => now(),
        ]);

        tenancy()->end();

        // Create Activation Code
        ActivationCode::create([
            'tenant_id' => $tenant->id,
            'code' => 'TEST-ACTIVATE-01',
            'tier' => 'pro',
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);
    }
}
