<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchasing & Cash Vault Blueprint, part B — the item's cost including
     * its pro-rata share of the PO's additional_costs_json (freight,
     * customs, handling), computed when the matching supplier invoice is
     * recorded. Visibility only for now: this does NOT feed back into
     * Product.cost_price, since retroactively adjusting an already-blended
     * weighted-average cost correctly would need cost-layer/batch tracking
     * SMARTPOS's stock model doesn't have. Null until an invoice with
     * additional costs is recorded against this item's GRV.
     */
    public function up(): void
    {
        Schema::table('grv_items', function (Blueprint $table) {
            $table->decimal('landed_unit_cost', 15, 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grv_items', function (Blueprint $table) {
            $table->dropColumn('landed_unit_cost');
        });
    }
};
