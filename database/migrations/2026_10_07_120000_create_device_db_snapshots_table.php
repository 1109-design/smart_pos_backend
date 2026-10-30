<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-demand copies of a device's local SQLite database, for debugging
 * (Flutter: core/sync/db_snapshot_uploader.dart).
 *
 * The developer device asks for one (POST /sync/diagnostics/db-snapshots);
 * the target device sees the request in its next diagnostics report,
 * uploads a gzipped VACUUM INTO copy in chunks, and the developer device
 * downloads it. Only the reader device(s) in
 * config('sync.diagnostics_reader_devices') can request or download.
 * Files live on the private local disk and are pruned after a week.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_db_snapshots')) {
            return;
        }

        Schema::create('device_db_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->unsignedBigInteger('device_id')->index();
            $table->unsignedBigInteger('requested_by_device_id')->nullable();
            // requested → uploading → ready (or failed).
            $table->string('status', 16)->default('requested');
            $table->unsignedInteger('chunks_total')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->unsignedInteger('schema_version')->nullable();
            $table->string('path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_db_snapshots');
    }
};
