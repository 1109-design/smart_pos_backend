<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-device sync diagnostics (Flutter: core/sync/sync_issue_reporter.dart).
 *
 * - device_sync_issues — one row per distinct problem per device. The
 *   device deduplicates by fingerprint (source + category + table +
 *   normalised message), so a record failing on every sync cycle bumps
 *   `occurrences` instead of adding rows. Never synced to other devices.
 * - device_sync_health — one row per device, overwritten on every report:
 *   app version, clock, outbox/cursor/row-count snapshot per table.
 *
 * Read back only through GET /sync/diagnostics, which is locked to the
 * developer device(s) in config('sync.diagnostics_reader_devices').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_sync_issues')) {
            Schema::create('device_sync_issues', function (Blueprint $table) {
                $table->id();
                $table->string('business_id')->nullable()->index();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('fingerprint', 64);
                // cloud_push, cloud_pull, cloud_sync, lan_push, lan_pull,
                // lan_apply, background — where in the app it happened.
                $table->string('source', 32);
                // What kind of problem (push_rejected, push_gave_up,
                // pull_bad_record, pull_skipped_local_dirty, http_error...).
                $table->string('category', 64);
                $table->string('table_name')->nullable();
                $table->text('message');
                // Most recent affected record uuids (device keeps the last 50).
                $table->json('record_uuids')->nullable();
                $table->unsignedInteger('record_uuid_count')->default(0);
                // Latest offending payload, sensitive keys stripped.
                $table->json('sample_payload')->nullable();
                // Latest context: HTTP status, response body, attempts, etc.
                $table->json('details')->nullable();
                $table->text('stack')->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->string('app_version')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamps();

                $table->unique(['device_id', 'fingerprint'], 'device_sync_issues_device_fp_unique');
                $table->index(['business_id', 'last_seen_at'], 'device_sync_issues_business_seen_idx');
            });
        }

        if (! Schema::hasTable('device_sync_health')) {
            Schema::create('device_sync_health', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('device_id')->unique();
                $table->string('business_id')->nullable()->index();
                $table->string('app_version')->nullable();
                $table->string('platform')->nullable();
                $table->string('current_user')->nullable();
                $table->timestamp('device_time')->nullable();
                // device_time - server receive time, in seconds. Positive =
                // device clock ahead. Sync ordering trusts client clocks for
                // updated_at, so a large skew is itself a sync suspect.
                $table->integer('clock_skew_seconds')->nullable();
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamp('last_successful_sync_at')->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sync_health');
        Schema::dropIfExists('device_sync_issues');
    }
};
