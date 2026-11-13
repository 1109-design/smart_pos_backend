<?php

use App\Services\Payroll\TillPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests added two till permissions (Dart `Permission` enum):
 * manageLeave (file leave for any employee) and approveLeave (decide it).
 * New businesses get them from the till's defaultRolePermissions; this
 * grants them to the existing rows of the roles that hold them by default,
 * matching the till's own v115 backfill. Only ever adds keys. Bumping
 * updated_at makes devices pull the row.
 */
return new class extends Migration
{
    private const ROLES = ['manager', 'branch_manager', 'operations_manager', 'hr_officer'];

    private const ADDED = ['manageLeave', 'approveLeave'];

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
