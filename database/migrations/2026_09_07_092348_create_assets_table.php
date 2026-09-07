<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('location_id')->nullable();
            $table->uuid('created_by_user_id')->nullable();

            $table->string('name');
            $table->string('category');
            $table->string('asset_tag')->nullable();

            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 2);
            $table->decimal('salvage_value', 15, 2)->default(0);

            // none | straight_line | reducing_balance
            $table->string('depreciation_method')->default('none');
            $table->unsignedInteger('useful_life_years')->nullable();
            $table->decimal('depreciation_rate_percent', 5, 2)->nullable();

            // active | under_repair | disposed | written_off
            $table->string('status')->default('active');
            $table->timestamp('disposed_at')->nullable();
            $table->decimal('disposal_value', 15, 2)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['business_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
