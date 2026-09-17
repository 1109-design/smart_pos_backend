<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which GL account backs an abstract posting "role" (e.g. 'salary_expense',
     * 'accounts_payable') for a business — see AccountRoleMappingService.
     * Lets Salary/Supplier-Payment/Asset posting resolve accounts without any
     * hardcoded GL code: the first resolve() call for a role auto-provisions
     * a sensible default and stores it here; an owner can reassign it to any
     * other GL account afterward from Settings.
     */
    public function up(): void
    {
        Schema::create('account_role_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('role');
            $table->uuid('gl_account_id');
            $table->timestamps();

            $table->unique(['business_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_role_mappings');
    }
};
