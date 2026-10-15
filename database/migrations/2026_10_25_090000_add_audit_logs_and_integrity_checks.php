<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — integrity and audit (Flutter v122).
 *
 * - audit_logs: who changed what master data or financial document, on
 *   which device, when, before and after. Written on devices, append-only
 *   here.
 * - integrity_check_runs: the nightly read-only check of each business's
 *   books (trial balance, every journal balances, control accounts vs
 *   sub-ledgers, Take-on Clearing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->nullable()->index();
            $table->string('user_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('model', 64);
            $table->string('record_id');
            $table->string('action', 16);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('at');
            $table->timestamp('created_at')->nullable();
            $table->index(['model', 'record_id']);
        });

        Schema::create('integrity_check_runs', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->boolean('ok');
            $table->json('findings');
            $table->timestamp('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_check_runs');
        Schema::dropIfExists('audit_logs');
    }
};
