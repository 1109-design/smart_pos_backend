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
        Schema::table('devices', function (Blueprint $table) {
            // Which shop/warehouse this till operates from. Nullable — unset
            // means "no restriction" so existing single-location businesses
            // and devices provisioned before this feature keep working
            // exactly as before.
            $table->uuid('location_id')->nullable()->after('device_identifier')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
