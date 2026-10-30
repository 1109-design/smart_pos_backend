<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-asset depreciation method, and how a disposed asset left the
 * business — see Asset::monthlyDepreciation() and
 * AssetPostingService::recordDisposal().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // declining_balance (every asset until now) | straight_line
            $table->string('depreciation_method')->default('declining_balance')->after('useful_life_months');
            // sold | returned | scrapped | stolen — null on an active asset
            // and on any disposal recorded before this column existed
            // (those were all sales).
            $table->string('disposal_method')->nullable()->after('disposal_proceeds');
            $table->string('disposal_supplier_id')->nullable()->after('disposal_method');
            $table->decimal('insurance_claim_amount', 20, 4)->nullable()->after('disposal_supplier_id');
            $table->text('disposal_notes')->nullable()->after('insurance_claim_amount');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'depreciation_method', 'disposal_method', 'disposal_supplier_id',
                'insurance_claim_amount', 'disposal_notes',
            ]);
        });
    }
};
