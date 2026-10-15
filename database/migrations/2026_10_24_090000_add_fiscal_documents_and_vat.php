<?php

use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — VAT and fiscalisation (Flutter v121).
 *
 * - fiscal_documents: what a device asks ZIMRA to fiscalise besides a till
 *   sale — a customer invoice (FiscalInvoice), and credit / debit notes
 *   against a fiscalised sale or invoice (refunds, returns, voids,
 *   corrections, customer credit and debit notes). The server's queue
 *   submits them through the same device chain as till sales.
 * - zimra_sales: a row is a till sale's or a fiscal document's.
 * - 2035 VAT Payable (ZIMRA) and 1155 VAT Refund Due: where the month-end
 *   VAT return nets output against input VAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->nullable();
            $table->string('document_type', 16);
            $table->string('source_type', 32);
            $table->string('source_id');
            $table->string('document_number');
            $table->uuid('original_transaction_id')->nullable()->index();
            $table->uuid('original_document_id')->nullable()->index();
            $table->uuid('customer_id')->nullable();
            $table->string('currency_code', 10);
            $table->decimal('total', 15, 4);
            $table->json('lines');
            $table->json('payments');
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('fiscal_receipt_id')->nullable();
            $table->text('fiscal_qr_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->uuid('transaction_id')->nullable()->change();
            $table->uuid('fiscal_document_id')->nullable()->unique();
        });

        $seeder = app(ChartOfAccountsSeeder::class);
        foreach (DB::table('account_categories')->distinct()->pluck('business_id') as $businessId) {
            foreach (ChartOfAccountsSeeder::PHASE11_ACCOUNTS as [$category, $subCategory, $account]) {
                $seeder->ensureAccount($businessId, $category, $subCategory, $account);
            }
        }
    }

    public function down(): void
    {
        Schema::table('zimra_sales', function (Blueprint $table) {
            $table->dropUnique(['fiscal_document_id']);
            $table->dropColumn('fiscal_document_id');
        });
        Schema::dropIfExists('fiscal_documents');
    }
};
