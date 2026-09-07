<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STC·08 — variance-threshold recount gate. flagged_for_recount marks an
 * item whose variance exceeded the business's configured threshold
 * (Business::stockTakeVarianceThresholdPercent()) at the moment it was
 * counted; recount_completed_at is stamped once that item is counted
 * again with a different value. StockTakesController::approve() (and the
 * till's own approval path) refuse to finalize while any item is flagged
 * with no recount yet — see SyncProcessor's stock_take_items case for
 * where the flag itself gets set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_items', function (Blueprint $table) {
            $table->boolean('flagged_for_recount')->default(false)->after('notes');
            $table->timestamp('recount_completed_at')->nullable()->after('flagged_for_recount');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_items', function (Blueprint $table) {
            $table->dropColumn(['flagged_for_recount', 'recount_completed_at']);
        });
    }
};
