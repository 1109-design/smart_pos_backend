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
        Schema::create('till_cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->index();
            $table->uuid('till_id')->index();
            $table->uuid('shift_id')->nullable()->index();
            $table->string('type'); // cash_in | cash_out
            $table->decimal('amount', 15, 4);
            $table->text('reason')->nullable();
            $table->uuid('recorded_by_user_id');
            $table->timestamps();

            $table->index(['business_id', 'till_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('till_cash_movements');
    }
};
