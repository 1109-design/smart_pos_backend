<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9 / Phase 11d — the asset register. BackOffice-authored only,
     * no till involvement at all (unlike Requisitions/Projects) — an asset
     * is a business-level accounting record (a delivery van, a display
     * fridge), never something a cashier creates or reads. Straight-line
     * depreciation only for now; useful_life_months + salvage_value is
     * everything AssetPostingService needs to compute a monthly charge.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('asset_number')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('notes')->nullable();
            $table->date('acquisition_date');
            $table->decimal('acquisition_cost', 15, 4);
            $table->decimal('salvage_value', 15, 4)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->string('funding_method')->default('cash'); // cash | bank — which account was credited on acquisition
            $table->string('status')->default('active'); // active | disposed
            $table->date('disposed_at')->nullable();
            $table->decimal('disposal_proceeds', 15, 4)->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
