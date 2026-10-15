<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — general journals captured on a device (Flutter v116).
 *
 * - manual_journals: the journal as a document — number, type, date,
 *   reference, narration, status (draft → submitted → pending_approval →
 *   approved → posted, or rejected; reversed), who made / approved /
 *   reversed it and the ledger journal it posted. Synced with its lines.
 * - manual_journal_lines: line type (gl / customer / supplier / bank /
 *   cash), account or party or bank account, debit / credit, currency, tax.
 * - journal_attachments: files attached to a journal (stored privately).
 * - journal_templates: saved journals to start new ones from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('document_number');
            $table->string('journal_type', 24)->default('general');
            $table->date('trans_date');
            $table->string('reference')->nullable();
            $table->text('narration');
            $table->uuid('location_id')->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('status', 24)->default('draft');
            $table->date('auto_reverse_date')->nullable();
            $table->uuid('corrects_journal_id')->nullable();
            $table->string('import_ref')->nullable();
            $table->uuid('approval_request_id')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->uuid('posted_journal_id')->nullable();
            $table->uuid('reversed_by_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'document_number']);
        });

        Schema::create('manual_journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('manual_journal_id')->index();
            $table->unsignedInteger('line_no');
            $table->string('line_type', 16)->default('gl');
            $table->uuid('gl_account_id')->nullable();
            $table->uuid('party_id')->nullable();
            $table->uuid('bank_account_id')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('currency_code', 8)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('foreign_amount', 18, 4)->nullable();
            $table->string('cost_centre_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->uuid('tax_rate_id')->nullable();
            $table->boolean('is_tax_line')->default(false);
            $table->timestamps();
        });

        Schema::create('journal_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('manual_journal_id')->index();
            $table->string('file_name');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name');
            $table->string('journal_type', 24)->default('general');
            $table->text('narration')->nullable();
            $table->json('lines');
            $table->uuid('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_templates');
        Schema::dropIfExists('journal_attachments');
        Schema::dropIfExists('manual_journal_lines');
        Schema::dropIfExists('manual_journals');
    }
};
