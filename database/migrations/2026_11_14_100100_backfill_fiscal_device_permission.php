<?php

use App\Services\Payroll\TillPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fiscal Device Management added the viewFiscalDevices till permission
 * (Dart `Permission` enum). New businesses get it from the till's
 * defaultRolePermissions; this grants it to the existing rows of the roles
 * that hold it by default, matching the till's own v116 backfill. Only ever
 * adds the key. Bumping updated_at makes devices pull the row.
 */
return new class extends Migration
{
    private const ROLES = ['manager', 'branch_manager', 'operations_manager', 'finance_manager', 'accountant'];

    private const ADDED = ['viewFiscalDevices'];

    public function up(): void
    {
        DB::table('role_permissions')->whereIn('role', self::ROLES)
            ->orderBy('business_id')->orderBy('role')
            ->each(function (object $row) {
                $permissions = TillPermissions::decode($row->permissions_json);
                $missing = array_values(array_diff(self::ADDED, $permissions));
                if ($missing === []) {
                    return;
                }

                DB::table('role_permissions')
                    ->where('business_id', $row->business_id)
                    ->where('role', $row->role)
                    ->update([
                        'permissions_json' => json_encode(array_values([...$permissions, ...$missing])),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Additive backfill.
    }
};
