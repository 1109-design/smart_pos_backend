<?php

use App\Services\Payroll\TillPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payroll split viewing pay data into its own permission (`viewPayroll`,
 * Dart `Permission` enum). New businesses get it from the till's
 * `defaultRolePermissions`; this grants it to every existing role that can
 * already run payroll (`processPayroll`), matching the till's own v106
 * backfill. Only ever adds the key. Bumping updated_at makes devices pull
 * the row.
 */
return new class extends Migration
{
    private const SOURCE = 'processPayroll';

    private const ADDED = 'viewPayroll';

    public function up(): void
    {
        DB::table('role_permissions')->orderBy('business_id')->orderBy('role')
            ->each(function (object $row) {
                $permissions = TillPermissions::decode($row->permissions_json);
                if (! in_array(self::SOURCE, $permissions, true)
                    || in_array(self::ADDED, $permissions, true)) {
                    return;
                }

                $permissions[] = self::ADDED;

                DB::table('role_permissions')
                    ->where('business_id', $row->business_id)
                    ->where('role', $row->role)
                    ->update([
                        'permissions_json' => json_encode(array_values($permissions)),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Additive backfill.
    }
};
