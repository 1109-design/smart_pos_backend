<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newest published app installer per platform, reported by CI as each
 * platform's build is published (POST /api/v1/app-releases). Relayed to
 * every device on /sync/pull, so tills learn of an update from the sync
 * they already run instead of each polling GitHub's API, whose 60 calls an
 * hour per shop IP the tills kept exhausting. One row per platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->string('platform')->primary(); // windows | linux | android
            $table->string('version');
            $table->string('asset_name');
            $table->string('download_url', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
