<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors the Flutter-side schema addition of the same name (Financial
     * Statements: warehouse/branch filtering + Cash Flow Statement account
     * classification). Flutter is the posting source of truth — these
     * columns only need to exist here so sync keeps matching column-for-
     * column; no backend UI reads them.
     */
    public function up(): void
    {
        Schema::table('journal_headers', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->index();
        });

        Schema::table('general_ledger', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->index();
        });

        Schema::table('account_categories', function (Blueprint $table) {
            // operating | investing | financing | null
            $table->string('cash_flow_section')->nullable();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_headers', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });

        Schema::table('general_ledger', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });

        Schema::table('account_categories', function (Blueprint $table) {
            $table->dropColumn('cash_flow_section');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
