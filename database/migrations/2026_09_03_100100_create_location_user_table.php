<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user scoped to zero locations here sees every location for their
     * business (today's behavior, unchanged) — this table is additive:
     * scoping only kicks in once a row exists for that user.
     */
    public function up(): void
    {
        Schema::create('location_user', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('location_id');
            $table->timestamps();

            $table->primary(['user_id', 'location_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_user');
    }
};
