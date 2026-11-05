<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'credit_notes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('fiscal_status')->nullable();
                $table->string('fiscal_receipt_number')->nullable();
                $table->text('fiscal_qr_code')->nullable();
                $table->string('fiscal_error')->nullable();
            });
        }

        foreach (['invoice_items', 'credit_note_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('tax_amount', 15, 4)->default(0);
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('vat_number')->nullable();
        });

        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->uuid('transaction_id')->nullable()->change();
            $table->uuid('invoice_id')->nullable()->unique();
            $table->uuid('credit_note_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
            $table->dropUnique(['credit_note_id']);
            $table->dropColumn(['invoice_id', 'credit_note_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('vat_number');
        });

        foreach (['invoice_items', 'credit_note_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('tax_amount');
            });
        }

        foreach (['invoices', 'credit_notes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['fiscal_status', 'fiscal_receipt_number', 'fiscal_qr_code', 'fiscal_error']);
            });
        }
    }
};
