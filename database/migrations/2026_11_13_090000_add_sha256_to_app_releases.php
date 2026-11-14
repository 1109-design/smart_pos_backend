<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SHA-256 of each hosted installer, computed by the release machine, checked
 * against the bytes the backend received, and relayed to devices, which refuse
 * a download that doesn't match. Null for releases recorded before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable()->after('download_url');
        });
    }

    public function down(): void
    {
        Schema::table('app_releases', function (Blueprint $table) {
            $table->dropColumn('sha256');
        });
    }
};
