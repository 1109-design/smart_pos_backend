<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.8 offline posting rule, matching the device's accounting_settings
 * (Flutter v113): within the last accounting_period_end_guard_days of a
 * period, or into an earlier one, a device that hasn't finished a sync in
 * accounting_offline_window_hours saves its journal as a draft until it
 * syncs. 0 hours turns the rule off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedSmallInteger('accounting_offline_window_hours')->default(24)->after('client_gl_posting_enabled_at');
            $table->unsignedTinyInteger('accounting_period_end_guard_days')->default(3)->after('accounting_offline_window_hours');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['accounting_offline_window_hours', 'accounting_period_end_guard_days']);
        });
    }
};
