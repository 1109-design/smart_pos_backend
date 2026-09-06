<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voiding a transaction previously captured no reason at all — the
     * manager PIN gate existed, but nothing recorded *why*. `notes` is
     * reused by the sale itself, so this is a dedicated column rather than
     * overloading that field.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->text('void_reason')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('void_reason');
        });
    }
};
