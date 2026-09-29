<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The till split "approve a customer return/exchange" into its own
 * permission (`approveSalesReturn`, Dart `Permission` enum). Before, any
 * manager/owner PIN cleared a refund. New businesses get it from the till's
 * `defaultRolePermissions`; this grants it to every existing role that can
 * already process refunds (`issueRefund` — the same manager-level roles).
 * Only ever adds the key. Bumping updated_at makes devices pull the row.
 */
return new class extends Migration
{
    private const SOURCE = 'issueRefund';

    private const ADDED = 'approveSalesReturn';

    public function up(): void
    {
        DB::table('role_permissions')->orderBy('business_id')->orderBy('role')
            ->each(function (object $row) {
                $permissions = $this->decode($row->permissions_json);
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
        // Additive backfill — see 2026_09_29_130000's identical note.
    }

    /**
     * Device-synced rows can hold the array double-encoded.
     *
     * @return list<string>
     */
    private function decode(mixed $raw): array
    {
        $value = $raw;
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
};
