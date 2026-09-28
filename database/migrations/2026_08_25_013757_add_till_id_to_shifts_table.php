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
        if (Schema::hasTable('shifts') && ! Schema::hasColumn('shifts', 'till_id')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->uuid('till_id')->nullable()->after('location_id')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('shifts') && Schema::hasColumn('shifts', 'till_id')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn('till_id');
            });
        }
    }
};
