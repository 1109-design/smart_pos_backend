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
        Schema::table('businesses', function (Blueprint $table) {
            // 'HH:mm' 24-hour time-of-day strings, not datetimes — these are
            // recurring daily boundaries, not one-off timestamps. Both null
            // (the default) means shift-aware reporting is off and every
            // report/dashboard query keeps using plain calendar midnight,
            // so existing businesses see no behaviour change until they
            // explicitly opt in from Settings.
            $table->string('day_shift_start', 5)->nullable()->after('fiscalisation_enabled');
            $table->string('night_shift_start', 5)->nullable()->after('day_shift_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['day_shift_start', 'night_shift_start']);
        });
    }
};
