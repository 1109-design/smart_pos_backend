<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this account has a physical card/POS swipe machine behind it —
     * gates whether the Flutter till offers it as a "POS Swipe" tender
     * option. Defaults true so an existing account's checkout behavior
     * doesn't change until explicitly turned off for one that's genuinely
     * not swipe-capable (e.g. an EFT-only account).
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->boolean('accepts_card_swipe')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('accepts_card_swipe');
        });
    }
};
