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
        Schema::table('products', function (Blueprint $table) {
            // Points at the surviving product once this one has been merged
            // away — never rewritten, only ever set once. Backend-only
            // bookkeeping: not part of the sync payload, since devices only
            // need is_active to know a merged product no longer sells.
            $table->uuid('merged_into_product_id')->nullable()->after('is_active');
            $table->index('merged_into_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['merged_into_product_id']);
            $table->dropColumn('merged_into_product_id');
        });
    }
};
