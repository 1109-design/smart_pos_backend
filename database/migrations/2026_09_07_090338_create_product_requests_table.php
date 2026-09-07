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
        Schema::create('product_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('location_id')->nullable();
            $table->uuid('requested_by_user_id')->nullable();

            $table->string('product_name');
            $table->text('note')->nullable();
            $table->string('status')->default('open'); // open | fulfilled

            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['business_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_requests');
    }
};
