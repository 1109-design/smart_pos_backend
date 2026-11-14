<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll audit log mirrored from the till (raw-SQL payroll_audit_log, see
 * payroll_audit.dart) — append-only, like the leave ledger. logged_at is the
 * till's own time; created_at is when it reached the server.
 *
 * Plus the server's check of each locked pay run's totals against its
 * employees and lines (PayRunTotalsCheck): when it ran, and what didn't add
 * up (null when everything did).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('entity_table');
            $table->uuid('entity_id');
            $table->uuid('employee_id')->nullable()->index();
            $table->uuid('pay_run_id')->nullable()->index();
            $table->string('action');
            $table->text('summary')->nullable();
            $table->text('changes')->nullable();
            $table->uuid('user_id')->nullable();
            $table->timestamp('logged_at')->nullable();
            $table->timestamps();
        });

        Schema::table('pay_runs', function (Blueprint $table) {
            $table->timestamp('totals_checked_at')->nullable();
            $table->json('totals_mismatch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pay_runs', function (Blueprint $table) {
            $table->dropColumn(['totals_checked_at', 'totals_mismatch']);
        });
        Schema::dropIfExists('payroll_audit_log');
    }
};
