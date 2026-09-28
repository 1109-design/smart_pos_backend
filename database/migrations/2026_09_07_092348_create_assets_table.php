<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Intentionally a no-op. Two branches each added an asset register; they
     * were merged and the 2026_09_06_113115 schema (acquisition_cost,
     * useful_life_months, funding_method) is the one the Asset model,
     * AssetsController, AssetPostingService and sync all use. This file
     * created a second, incompatible `assets` table and crashed every fresh
     * migrate ("table assets already exists"). Kept as an empty migration,
     * rather than deleted, so databases that already recorded it stay
     * consistent — and down() must never drop the real table.
     */
    public function up(): void {}

    public function down(): void {}
};
