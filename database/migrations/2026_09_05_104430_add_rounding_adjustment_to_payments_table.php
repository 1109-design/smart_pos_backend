<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-tender rounding (nearest coin denomination), folded into this
     * payment's base_equivalent by the till but kept explicit here too, so
     * it can't be confused with the pre-existing "owed change" case, which
     * also makes base_equivalent diverge from the transaction total for an
     * unrelated reason. Always 0 for non-cash methods and for sales made
     * before this column existed.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('rounding_adjustment', 15, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('rounding_adjustment');
        });
    }
};
