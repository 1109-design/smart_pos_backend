<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * STK·03 — nothing leaves the warehouse without a request → approve →
     * issue trail. Deliberately not a location-to-location move like
     * StockTransfer: a requisition only ever debits `location_id` (the
     * warehouse/branch it's issued from); "general use" or a project both
     * consume stock without a second location receiving it. `project_id`
     * has no FK yet — PRJ·04 (which depends on this table) is what
     * introduces the `projects` table it will eventually reference.
     */
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('requisition_number')->index(); // e.g. REQ-202609-001
            $table->uuid('location_id'); // warehouse/branch stock is issued from
            $table->string('purpose')->default('general'); // general | project
            $table->uuid('project_id')->nullable();
            $table->text('notes')->nullable();
            // pending | approved | rejected | issued | cancelled
            $table->string('status')->default('pending');
            $table->uuid('requested_by_user_id');
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('issued_by_user_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('requisition_id')->index();
            $table->uuid('product_id');
            $table->string('product_name'); // snapshot
            $table->decimal('quantity_requested', 15, 4);
            $table->decimal('quantity_issued', 15, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
    }
};
