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
        Schema::create('tills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('location_id')->index();
            // The device currently assigned to this till, if any — devices.id
            // is auto-increment, unlike every other table in this schema.
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('register_number');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'register_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tills');
    }
};
