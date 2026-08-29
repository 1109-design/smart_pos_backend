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
        Schema::table('businesses', function (Blueprint $table) {
            // Tracks the one-time "Reset Everything" action in BackOffice
            // Settings — once set, permanently locked out for this business
            // (see App\Services\CatalogueResetService). Deliberately its own
            // lock, separate from stock_reset_at: this wipes the entire
            // catalogue and sales history, a strictly bigger and more
            // destructive action than zeroing stock alone.
            $table->timestamp('catalogue_reset_at')->nullable()->after('stock_reset_by_user_id');
            $table->uuid('catalogue_reset_by_user_id')->nullable()->after('catalogue_reset_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['catalogue_reset_at', 'catalogue_reset_by_user_id']);
        });
    }
};
