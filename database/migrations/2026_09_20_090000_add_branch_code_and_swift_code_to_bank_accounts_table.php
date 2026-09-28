<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same two fields supplier_banks already carries (branch_code,
     * swift_code) — a business's own bank accounts never had them, only a
     * supplier's did.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('branch_code')->nullable()->after('branch');
            $table->string('swift_code')->nullable()->after('branch_code');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['branch_code', 'swift_code']);
        });
    }
};
