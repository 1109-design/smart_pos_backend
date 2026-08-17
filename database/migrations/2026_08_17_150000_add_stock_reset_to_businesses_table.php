<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Tracks the one-time "Reset All Stock" action in BackOffice
            // Settings — once set, the action is permanently locked out for
            // this business (see BackOffice\SettingsController).
            $table->timestamp('stock_reset_at')->nullable()->after('metadata');
            $table->uuid('stock_reset_by_user_id')->nullable()->after('stock_reset_at');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['stock_reset_at', 'stock_reset_by_user_id']);
        });
    }
};
