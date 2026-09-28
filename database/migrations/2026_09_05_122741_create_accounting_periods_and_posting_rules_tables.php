<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * accounting_periods: an owner can "close" a period to lock it against
     * further posting (corrections after that point must be a reversal
     * dated in the current open period instead). Absent a period row
     * covering a date, posting is allowed by default — matching the
     * opt-in-gate convention already used for Business::workflow_settings
     * elsewhere in this codebase; only an explicit closed period blocks a
     * post.
     *
     * posting_rules: a data-driven event_type -> debit/credit account
     * mapping (Phase 11b+ wires actual events to this), so remapping which
     * account a sale or an expense posts to is a settings change, not a
     * code change. Mirrors Blackrock's FinanceStockPostingRule/
     * FinanceSalesPostingService account-resolution pattern.
     */
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'period_start', 'period_end']);
        });

        Schema::create('posting_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('event_type');
            $table->uuid('debit_account_id')->nullable();
            $table->uuid('credit_account_id')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posting_rules');
        Schema::dropIfExists('accounting_periods');
    }
};
