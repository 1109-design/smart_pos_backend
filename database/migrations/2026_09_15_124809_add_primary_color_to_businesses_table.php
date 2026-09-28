<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * '#RRGGBB', nullable — null means "no branding set, render the
     * default SmartPOS theme" everywhere this is read. See
     * Business::publishBrandingSyncRecord() for how this reaches devices.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('primary_color', 7)->nullable()->after('logo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('primary_color');
        });
    }
};
