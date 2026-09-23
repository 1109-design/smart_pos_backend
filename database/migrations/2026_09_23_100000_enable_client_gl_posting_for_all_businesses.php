<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable client-side GL posting for every business that already has a
 * chart of accounts set up.
 *
 * Background: Flutter now posts GL journals immediately at checkout without
 * requiring a backend-issued cutover date. The Laravel SalePostingService
 * must stand down for all such businesses to prevent double-posting.
 *
 * The client flag (`client_gl_posting_enabled_at`) is the existing
 * mechanism for this — when it is non-null, `Business::postsFromClientFor()`
 * returns true and the backend posting services skip the transaction.
 *
 * This migration back-fills the flag to `NOW()` for every tenant that has
 * at least one GL account (i.e. a chart of accounts was seeded or created),
 * ensuring the server immediately stands down for those businesses and new
 * sales posted by the device are not re-posted by the sync processor.
 *
 * Businesses without a chart of accounts are left untouched — they have
 * no journals to post yet, and the flag will be written the moment
 * AccountingSettingsService::save() is called from the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        // This app runs single-DB tenancy: the tenant-scoped record is the
        // `businesses` table itself (client_gl_posting_enabled_at and
        // accounting_go_live_date both live there — see the two migrations
        // that added them), not the separate `tenants` table.
        //
        // We operate on the businesses table directly rather than the
        // Business Eloquent model so this migration runs cleanly even
        // outside a request (e.g. `php artisan migrate` on the server
        // without a tenant context).

        // Find every business_id that has at least one GL account row.
        $businessIdsWithAccounts = DB::table('gl_accounts')
            ->select('business_id')
            ->distinct()
            ->pluck('business_id');

        if ($businessIdsWithAccounts->isEmpty()) {
            return; // nothing to back-fill
        }

        // Set client_gl_posting_enabled_at = NOW() for matching businesses
        // that do not already have it set (never overwrite an earlier date —
        // that would retroactively re-post older transactions the server may
        // have already handled).
        DB::table('businesses')
            ->whereIn('id', $businessIdsWithAccounts)
            ->whereNull('client_gl_posting_enabled_at')
            ->update(['client_gl_posting_enabled_at' => now()]);

        // Also push accounting_settings sync records so every connected
        // device receives the cutover and its own isClientGlPostingEnabledFor
        // check (which now returns true based on GL account existence rather
        // than this flag, but the flag is still broadcast for the backend's
        // own guard). Use raw insert to avoid loading Eloquent tenant models.
        $now = now()->toIso8601String();
        foreach ($businessIdsWithAccounts as $businessId) {
            $goLiveDate = DB::table('businesses')
                ->where('id', $businessId)
                ->value('accounting_go_live_date');

            DB::table('sync_records')->insert([
                'business_id' => $businessId,
                'table_name' => 'accounting_settings',
                'record_uuid' => $businessId,
                'operation' => 'upsert',
                'payload' => json_encode([
                    'business_id' => $businessId,
                    'accounting_go_live_date' => $goLiveDate,
                    'client_gl_posting_enabled_at' => $now,
                    'updated_at' => $now,
                ]),
                'source_updated_at' => now(),
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: we cannot know which businesses had the flag
        // set before this migration ran, so reverting blindly would incorrectly
        // clear flags that were already set by AccountingSettingsService.
        // If you need to roll back, set client_gl_posting_enabled_at = NULL
        // manually for the affected businesses.
    }
};
