<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Locations (warehouses & shops) ────────────────────────────────────
        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->uuid('parent_id')->nullable(); // warehouse that feeds this shop
                $table->string('name');
                $table->string('type')->default('shop'); // shop | warehouse
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->boolean('can_sell')->default(true);
                $table->boolean('can_receive')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'type']);
            });
        }

        // ── Per-location stock levels ─────────────────────────────────────────
        if (! Schema::hasTable('product_stock')) {
            Schema::create('product_stock', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('product_id')->index();
                $table->uuid('location_id')->index();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('reserved_quantity', 15, 4)->default(0); // in transit / pending
                $table->timestamps();

                $table->unique(['product_id', 'location_id']);
            });
        }

        // ── Per-location stock for variants ───────────────────────────────────
        if (! Schema::hasTable('product_variant_stock')) {
            Schema::create('product_variant_stock', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('variant_id')->index();
                $table->uuid('location_id')->index();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('reserved_quantity', 15, 4)->default(0);
                $table->timestamps();

                $table->unique(['variant_id', 'location_id']);
            });
        }

        // ── Stock transfers between locations ─────────────────────────────────
        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('business_id')->index();
                $table->string('transfer_number')->index(); // e.g. TRF-202505-001
                $table->uuid('from_location_id');
                $table->uuid('to_location_id');
                // pending | approved | in_transit | received | cancelled
                $table->string('status')->default('pending');
                $table->text('notes')->nullable();
                $table->uuid('requested_by_user_id');
                $table->uuid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
            });
        }

        if (! Schema::hasTable('stock_transfer_items')) {
            Schema::create('stock_transfer_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('stock_transfer_id')->index();
                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();
                $table->string('product_name'); // snapshot
                $table->decimal('qty_requested', 15, 4);
                $table->decimal('qty_sent', 15, 4)->default(0);
                $table->decimal('qty_received', 15, 4)->default(0);
                $table->text('notes')->nullable();
            });
        }

        // ── Add location_id to existing tables ────────────────────────────────
        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'location_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->uuid('location_id')->nullable()->after('business_id')->index();
            });
        }

        if (Schema::hasTable('stock_movements') && ! Schema::hasColumn('stock_movements', 'location_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->uuid('location_id')->nullable()->after('business_id')->index();
                $table->uuid('to_location_id')->nullable()->after('location_id'); // for transfer movements
            });
        }

        if (Schema::hasTable('stock_takes') && ! Schema::hasColumn('stock_takes', 'location_id')) {
            Schema::table('stock_takes', function (Blueprint $table) {
                $table->uuid('location_id')->nullable()->after('business_id')->index();
            });
        }

        if (Schema::hasTable('purchase_orders') && ! Schema::hasColumn('purchase_orders', 'receiving_location_id')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->uuid('receiving_location_id')->nullable()->after('business_id')->index();
            });
        }

        if (Schema::hasTable('shifts') && ! Schema::hasColumn('shifts', 'location_id')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->uuid('location_id')->nullable()->after('business_id')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['stock_transfer_items', 'stock_transfers', 'product_variant_stock', 'product_stock', 'locations'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach ([
            'transactions' => ['location_id'],
            'stock_movements' => ['location_id', 'to_location_id'],
            'stock_takes' => ['location_id'],
            'purchase_orders' => ['receiving_location_id'],
            'shifts' => ['location_id'],
        ] as $table => $columns) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                    $blueprint->dropColumn($columns);
                });
            }
        }
    }
};
