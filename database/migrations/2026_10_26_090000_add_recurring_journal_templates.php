<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring journals (Flutter v123): a journal template can repeat weekly,
 * monthly or quarterly. Devices make each run's draft journal; it posts
 * only through the usual journal approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->string('recur_frequency', 16)->nullable();
            $table->date('recur_next_date')->nullable();
            $table->date('recur_end_date')->nullable();
            $table->boolean('recur_active')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->dropColumn(['recur_frequency', 'recur_next_date', 'recur_end_date', 'recur_active']);
        });
    }
};
