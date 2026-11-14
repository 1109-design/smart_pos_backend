<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's audit trail (Flutter: core/audit/audit_log.dart): who did
 * what, when, on which till. Each device uploads its entries through
 * POST /audit/push and keeps only today's locally; this table is the full
 * history, kept forever, read back through GET /audit. Append-only —
 * nothing here is ever updated or deleted, and it is never synced to
 * devices.
 *
 * `seq` is each device's own running number; a gap or a restart in it is
 * recorded as an `integrity` entry, so rows deleted on a device before they
 * were uploaded show up in the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('entry_id')->unique();
            $table->string('business_id', 64);
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('device_name')->nullable();
            // Null on server-made integrity entries.
            $table->unsignedBigInteger('seq')->nullable();
            // Device clock, as reported; received_at is the server's.
            $table->dateTime('occurred_at', 3);
            $table->dateTime('received_at', 3);
            // event | data_change | integrity
            $table->string('kind', 16);
            $table->string('action', 64);
            $table->string('category', 32);
            $table->boolean('important')->default(true);
            $table->string('table_name', 64)->nullable();
            $table->string('record_id', 191)->nullable();
            $table->text('summary')->nullable();
            $table->json('details')->nullable();
            // user | system | server
            $table->string('actor', 16)->default('user');
            $table->string('user_id', 64)->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_role', 64)->nullable();
            $table->string('location_id', 64)->nullable();
            $table->string('till_id', 64)->nullable();
            $table->string('app_version', 64)->nullable();

            $table->index(['business_id', 'occurred_at']);
            $table->index(['business_id', 'user_id', 'occurred_at']);
            $table->index(['business_id', 'action', 'occurred_at']);
            $table->index(['device_id', 'seq']);
            $table->index(['business_id', 'table_name', 'record_id', 'occurred_at'], 'audit_entries_record_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
