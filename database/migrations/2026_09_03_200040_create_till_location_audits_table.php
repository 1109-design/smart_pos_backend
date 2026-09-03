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
        Schema::create('till_location_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('business_id');
            $table->uuid('till_id');
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id');
            $table->uuid('changed_by_user_id');
            $table->string('changed_by_user_name');
            $table->timestamp('created_at')->useCurrent();

            $table->index('till_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('till_location_audits');
    }
};
