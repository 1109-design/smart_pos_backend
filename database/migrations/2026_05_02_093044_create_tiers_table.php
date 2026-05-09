<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('price');
            $table->json('features')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the three built-in tiers
        DB::table('tiers')->insert([
            [
                'key'        => 'starter',
                'label'      => 'Starter',
                'price'      => 'Free forever',
                'features'   => json_encode(['Unlimited local transactions', '500 products', 'Barcode scan', 'Basic reports']),
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key'        => 'pro',
                'label'      => 'Pro',
                'price'      => '$9/month',
                'features'   => json_encode(['Unlimited products', 'Discounts & coupons', 'Customer loyalty', 'Advanced reports', 'Cloud sync (single branch)']),
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key'        => 'ultimate',
                'label'      => 'Ultimate',
                'price'      => '$29/month',
                'features'   => json_encode(['Everything in Pro', 'Multi-branch sync', 'Web admin portal', 'API integrations', 'Priority support']),
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers');
    }
};
