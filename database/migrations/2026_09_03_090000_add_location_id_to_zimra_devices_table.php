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
        Schema::table('zimra_devices', function (Blueprint $table) {
            // Nullable: null means "business-wide fallback device" so existing
            // single-device businesses keep working unchanged. A chain with
            // multiple branches registers one device per location instead.
            $table->uuid('location_id')->nullable()->after('business_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zimra_devices', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
