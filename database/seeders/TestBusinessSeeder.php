<?php

namespace Database\Seeders;

use App\Models\ActivationCode;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestBusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Create Tenant in central DB
        $tenant = Tenant::firstOrCreate(
            ['id' => 'test-business'],
            [
                'business_name' => 'Test Business',
                'owner_email'   => 'test@example.com',
                'tier'          => 'pro',
                'pairing_code'  => 'TEST66',
                'is_active'     => true,
                'country'       => 'ZW',
                'currency_code' => 'USD',
            ]
        );

        $tenant->domains()->firstOrCreate(['domain' => 'test-business.localhost']);

        // Seed data inside the tenant context
        // Note: CreateDatabase + MigrateDatabase run automatically via TenantCreated event pipeline
        $tenant->run(function () use ($tenant) {
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            $this->call(RolesAndPermissionsSeeder::class);

            $user = \App\Models\User::firstOrCreate(
                ['email' => 'admin@test.com'],
                [
                    'name'      => 'Test Admin',
                    'password'  => Hash::make('password'),
                    'pin_hash'  => Hash::make('1234'),
                    'is_active' => true,
                ]
            );

            $user->assignRole('manager');

            // Sync record for user (tenant DB: no business_id column)
            \App\Models\SyncRecord::firstOrCreate(
                ['table_name' => 'users', 'record_uuid' => $user->id],
                [
                    'operation' => 'upsert',
                    'payload'   => array_merge($user->toArray(), ['role' => 'manager']),
                    'synced_at' => now(),
                ]
            );

            // Business profile in tenant DB (no country column)
            $business = \App\Models\Business::firstOrCreate(
                ['id' => $tenant->id],
                [
                    'name'          => $tenant->business_name,
                    'email'         => $tenant->owner_email,
                    'currency_code' => $tenant->currency_code,
                ]
            );

            // Sync record for business
            \App\Models\SyncRecord::firstOrCreate(
                ['table_name' => 'businesses', 'record_uuid' => $business->id],
                [
                    'operation' => 'upsert',
                    'payload'   => $business->toArray(),
                    'synced_at' => now(),
                ]
            );
        });

        // Activation Code in central DB
        ActivationCode::firstOrCreate(
            ['code' => 'TEST-ACTIVATE-01'],
            [
                'tenant_id'  => $tenant->id,
                'tier'       => 'pro',
                'status'     => 'pending',
                'expires_at' => now()->addDays(7),
            ]
        );
    }

}
