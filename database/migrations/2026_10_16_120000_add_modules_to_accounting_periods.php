<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — accounting periods per module, closed and reopened from a
 * device through approval and synced to every device.
 *
 * - module: finance | sales | stock | ar | ap. A journal is blocked when
 *   the finance period or its own module's period is closed for its date.
 * - status: open | closed | locked (locked = closed by year end; can't be
 *   reopened).
 * - who reopened it, and the device that closed it.
 * One period per business, module and start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->string('module', 16)->default('finance')->after('business_id');
            $table->string('status', 16)->default('open')->change();
            $table->string('closed_by_device_id')->nullable()->after('closed_by_user_id');
            $table->timestamp('reopened_at')->nullable()->after('closed_by_device_id');
            $table->uuid('reopened_by_user_id')->nullable()->after('reopened_at');
            $table->unique(['business_id', 'module', 'period_start'], 'accounting_periods_business_module_start_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropUnique('accounting_periods_business_module_start_unique');
            $table->dropColumn(['module', 'closed_by_device_id', 'reopened_at', 'reopened_by_user_id']);
        });
    }
};
