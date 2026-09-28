<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AP module — supplier statement reconciliation (spec §25). Mirrors
     * the Flutter-side SupplierReconciliations/SupplierReconciliationItems
     * shape 1:1 — see tables.dart's own doc comment.
     */
    public function up(): void
    {
        Schema::create('supplier_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('supplier_id')->index();
            $table->date('statement_date');
            $table->decimal('statement_closing_balance', 15, 4);
            $table->decimal('smart_pos_closing_balance', 15, 4);
            $table->decimal('variance', 15, 4);
            $table->string('status')->default('in_progress'); // in_progress | completed | disputed
            $table->string('notes')->nullable();
            $table->uuid('reconciled_by_user_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_reconciliation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reconciliation_id')->index();
            $table->string('document_type'); // invoice | payment | credit_note | debit_note | adjustment
            $table->string('document_reference');
            $table->date('document_date');
            $table->decimal('supplier_amount', 15, 4)->default(0);
            $table->decimal('smart_pos_amount', 15, 4)->default(0);
            $table->decimal('difference', 15, 4)->default(0);
            $table->string('status')->default('matched');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_reconciliation_items');
        Schema::dropIfExists('supplier_reconciliations');
    }
};
