<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Superseded by the append-only till_location_audits table (added in
        // the migration just before this one) — matches the PoAuditLog
        // pattern instead of two mutable "last change" columns that could
        // only ever remember one prior move.
        Schema::table('tills', function (Blueprint $table) {
            $table->dropColumn(['location_changed_at', 'location_changed_by_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tills', function (Blueprint $table) {
            $table->timestamp('location_changed_at')->nullable();
            $table->string('location_changed_by_user_id')->nullable();
        });
    }
};
