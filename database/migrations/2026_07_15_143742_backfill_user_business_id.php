<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Assign a business_id to users that predate tenant isolation.
     *
     * Users used to be created without business_id, so once the User model's
     * tenant scope goes live they would become invisible inside a tenant
     * context and unable to authenticate. Owners can be recovered
     * unambiguously by matching the central tenants.owner_email. Any remaining
     * users (non-owner employees created before this fix) have no stored
     * business link and must be reassigned operationally — their count is
     * logged so the gap is visible rather than silent.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('business_id')
            ->orderBy('id')
            ->each(function ($user): void {
                $tenant = DB::table('tenants')
                    ->where('owner_email', $user->email)
                    ->first();

                if ($tenant) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['business_id' => $tenant->id]);
                }
            });

        $unassigned = DB::table('users')->whereNull('business_id')->count();

        if ($unassigned > 0) {
            Log::warning("backfill_user_business_id: {$unassigned} user(s) still have no business_id and must be reassigned manually.");
        }
    }

    public function down(): void
    {
        // Irreversible data backfill; nothing to roll back.
    }
};
