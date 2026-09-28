<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PUR·02 — a period budget for purchasing spend, checked at the same
     * gate an over-threshold single PO already goes through (see
     * SyncProcessor::gatePurchaseOrderStatus() /
     * App\Services\Accounting\PurchaseOrderApprovalGate, built earlier this
     * session for the flat per-PO threshold). Deliberately period-only, not
     * also per-order: a per-order cap would mostly duplicate what the
     * existing flat threshold already does (stop one PO that's too big on
     * its own), where a period budget catches the case that gate can't —
     * many small, individually-fine POs that add up over a month.
     */
    public function up(): void
    {
        Schema::create('procurement_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 15, 4);
            $table->uuid('created_by_user_id');
            $table->timestamps();

            $table->index(['business_id', 'period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procurement_budgets');
    }
};
