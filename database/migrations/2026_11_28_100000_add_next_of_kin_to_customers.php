<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Next of kin on a customer record — who to contact for the customer
     * (e.g. on credit or layby accounts). Captured on the till's customer
     * form; all optional.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('next_of_kin_name')->nullable()->after('vat_number');
            $table->string('next_of_kin_relationship', 50)->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_phone', 30)->nullable()->after('next_of_kin_relationship');
            $table->string('next_of_kin_address')->nullable()->after('next_of_kin_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'next_of_kin_name',
                'next_of_kin_relationship',
                'next_of_kin_phone',
                'next_of_kin_address',
            ]);
        });
    }
};
