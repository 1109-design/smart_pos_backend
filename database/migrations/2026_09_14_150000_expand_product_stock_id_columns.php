<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand product_stock.id and product_variant_stock.id from char(36) → varchar(255).
 *
 * Why: SyncProcessor::handleUpsert() writes the sync record's uuid straight into
 * these tables' `id` column (see product_stock/product_variant_stock cases). The
 * Flutter client keys per-location stock rows by a deterministic composite id
 * ("productId|locationId", 73 chars — see ProductStockCompanion usage in
 * sync_service.dart), which overflows the original uuid() column, causing
 * SQLSTATE[22001] "Data too long for column 'id'" and endless push retries.
 * Same root cause already fixed once for sync_records/sync_conflicts.record_uuid
 * in 2026_05_09_181900_expand_record_uuid_columns.php.
 *
 * Safe: purely expands column capacity, no data is lost or altered.
 */
return new class extends Migration
{
    private array $tables = ['product_stock', 'product_variant_stock'];

    public function up(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            foreach ($this->tables as $table) {
                try {
                    $schema = Schema::connection($connection);
                    if (! $schema->hasTable($table)) {
                        continue;
                    }

                    $schema->table($table, function (Blueprint $bp) {
                        $bp->string('id', 255)->change();
                    });
                } catch (Exception $e) {
                    // Skip connection when no database is configured (e.g. tenant during central migration)
                    continue;
                }
            }
        }
    }

    public function down(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            foreach ($this->tables as $table) {
                try {
                    $schema = Schema::connection($connection);
                    if (! $schema->hasTable($table)) {
                        continue;
                    }

                    $schema->table($table, function (Blueprint $bp) {
                        // Revert to uuid (char 36) — note this will silently truncate composite keys
                        $bp->uuid('id')->change();
                    });
                } catch (Exception $e) {
                    continue;
                }
            }
        }
    }
};
