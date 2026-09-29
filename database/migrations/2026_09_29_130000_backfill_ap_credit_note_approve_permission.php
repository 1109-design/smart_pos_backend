<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The till split "approve supplier credit/debit notes" out into its own
 * permission (`apCreditNoteApprove`, Dart `Permission` enum) instead of
 * reusing `apInvoiceApprove`. New businesses get it from the till's
 * `defaultRolePermissions`, but a role row that already exists here was
 * seeded before the key existed and would silently lose the ability to
 * approve credit notes. Grants it to every role that can already approve
 * supplier invoices — the same people who could approve notes before the
 * split. Only ever adds the key; never removes anything, so an owner's
 * explicit revocation elsewhere stays intact. Bumping updated_at makes
 * devices pull the corrected row on their next sync.
 */
return new class extends Migration
{
    private const SOURCE = 'apInvoiceApprove';

    private const ADDED = 'apCreditNoteApprove';

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
        // Additive backfill — the key is harmless to leave on a role, and
        // stripping it could remove a grant an owner made deliberately.
    }

    /**
     * Rows synced from a device can hold the JSON array double-encoded (a
     * JSON string of a JSON array) — see SyncProcessor's role_permissions
     * case, which stores the device's already-encoded payload into an
     * `array`-cast column.
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
