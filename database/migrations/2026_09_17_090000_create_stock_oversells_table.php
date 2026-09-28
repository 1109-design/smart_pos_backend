<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            try {
                $schema = Schema::connection($connection);

                if ($schema->hasTable('stock_oversells')) {
                    continue;
                }

                $schema->create('stock_oversells', function (Blueprint $table) {
                    $table->id();
                    $table->string('business_id')->index();
                    $table->uuid('product_id')->index();
                    $table->uuid('location_id')->nullable();
                    $table->decimal('computed_quantity', 14, 4);
                    $table->decimal('shortfall', 14, 4);
                    $table->timestamp('detected_at');
                    $table->timestamp('resolved_at')->nullable();
                    $table->uuid('resolved_by_user_id')->nullable();
                    $table->string('resolution')->nullable();
                    $table->text('notes')->nullable();
                    $table->timestamps();

                    $table->index(['business_id', 'resolved_at'], 'stock_oversells_business_resolved_idx');
                });
            } catch (\Exception $e) {
                // Skip connection when no database is selected (e.g. tenant during central migration)
                continue;
            }
        }
    }

    public function down(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            try {
                $schema = Schema::connection($connection);
                if ($schema->hasTable('stock_oversells')) {
                    $schema->drop('stock_oversells');
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }
};
