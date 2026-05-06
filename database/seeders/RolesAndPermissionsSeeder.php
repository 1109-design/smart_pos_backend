<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'manage_users',
            'manage_devices',
            'manage_inventory',
            'manage_suppliers',
            'view_reports',
            'export_reports',
            'process_sale',
            'void_transaction',
            'apply_discount',
            'manage_subscriptions',
            'view_all_branches',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->syncPermissions(Permission::all());

        $businessOwner = Role::firstOrCreate(['name' => 'business_owner']);
        $businessOwner->syncPermissions([
            'manage_users', 'manage_devices', 'manage_inventory', 'manage_suppliers',
            'view_reports', 'export_reports', 'process_sale', 'void_transaction',
            'apply_discount', 'manage_subscriptions', 'view_all_branches',
        ]);

        $manager = Role::firstOrCreate(['name' => 'manager']);
        $manager->syncPermissions([
            'manage_users', 'manage_inventory', 'manage_suppliers',
            'view_reports', 'export_reports', 'process_sale',
            'void_transaction', 'apply_discount',
        ]);

        $cashier = Role::firstOrCreate(['name' => 'cashier']);
        $cashier->syncPermissions([
            'process_sale',
        ]);
    }
}
