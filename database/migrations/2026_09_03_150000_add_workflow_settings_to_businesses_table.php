<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in, per-business workflow toggles — e.g.
     * {"stock_transfer_requires_approval": true}. Absent key or null column
     * means "not configured," which must behave identically to today's
     * current (gate-free) behavior — matching the existing per-business-
     * switch convention (fiscalisation_enabled) rather than the platform-
     * wide `Setting` key/value model, which is global-admin-only.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('workflow_settings')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('workflow_settings');
        });
    }
};
