<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiscalisation target: how much gross sales (USD) the business plans to
     * fiscalise per period. Set on the till (Settings → Business Profile) and
     * shown to cashiers as a progress meter on the POS. Null = no target.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->decimal('fiscal_target_usd', 15, 2)->nullable()->after('fiscalise_by_default');
            $table->string('fiscal_target_period', 10)->default('daily')->after('fiscal_target_usd');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['fiscal_target_usd', 'fiscal_target_period']);
        });
    }
};
