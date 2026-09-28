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
        Schema::table('tills', function (Blueprint $table) {
            $table->timestamp('location_changed_at')->nullable();
            $table->string('location_changed_by_user_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tills', function (Blueprint $table) {
            $table->dropColumn(['location_changed_at', 'location_changed_by_user_id']);
        });
    }
};
