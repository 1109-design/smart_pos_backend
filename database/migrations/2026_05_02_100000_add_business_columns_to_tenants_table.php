<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('business_name')->after('id');
            $table->string('owner_email')->after('business_name');
            $table->enum('tier', ['starter', 'pro', 'ultimate'])->default('starter')->after('owner_email');
            $table->timestamp('subscription_valid_until')->nullable()->after('tier');
            $table->boolean('is_active')->default(true)->after('subscription_valid_until');
            $table->string('country', 2)->nullable()->after('is_active');
            $table->string('currency_code', 10)->default('USD')->after('country');
            $table->string('logo_path')->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'business_name', 'owner_email', 'tier',
                'subscription_valid_until', 'is_active',
                'country', 'currency_code', 'logo_path',
            ]);
        });
    }
};
