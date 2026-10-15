<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — cost centres (Flutter v120).
 *
 * - cost_centres: hierarchical (parent_id); a split one holds percentages
 *   of others and divides a posting line between them on the device.
 * - Defaults on locations, product categories and GL accounts; overrides
 *   on expenses and supplier invoice lines (journal lines already have
 *   cost_centre_id).
 * - businesses.cost_centre_required, via accounting_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('code', 32);
            $table->string('name');
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_split')->default(false);
            $table->json('split');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        foreach (['locations', 'categories', 'expenses', 'supplier_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('cost_centre_id')->nullable();
            });
        }
        Schema::table('gl_accounts', function (Blueprint $table) {
            $table->uuid('default_cost_centre_id')->nullable();
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('cost_centre_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('cost_centre_required'));
        Schema::table('gl_accounts', fn (Blueprint $table) => $table->dropColumn('default_cost_centre_id'));
        foreach (['locations', 'categories', 'expenses', 'supplier_invoice_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('cost_centre_id'));
        }
        Schema::dropIfExists('cost_centres');
    }
};
