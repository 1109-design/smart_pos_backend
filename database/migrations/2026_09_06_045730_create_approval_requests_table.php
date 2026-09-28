<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic approval queue shared by every workflow that needs a
     * supervisor gate (void/refund, exchange-rate changes, and future
     * requisitions/discounts). `subject_type`/`subject_id` is the same
     * polymorphic-by-convention shape already used for `JournalHeader`'s
     * `source_type`/`source_id` — deliberately reused rather than inventing
     * a new linking convention. Resolved synchronously via the till's
     * existing manager-PIN dialog in the common case (a manager is on
     * site); this table only becomes visible in BackOffice for the case
     * where no eligible approver is present locally.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('action');
            $table->uuid('requested_by_user_id');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->uuid('approver_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
