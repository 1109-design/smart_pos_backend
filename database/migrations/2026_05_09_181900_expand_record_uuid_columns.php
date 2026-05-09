<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Expand record_uuid from char(36) → varchar(255) in both sync_records and sync_conflicts.
 *
 * Why: composite keys (e.g. "productId|taxRateId" for product_tax_rates) are 73 chars,
 * which overflows the original uuid() column (char(36)) causing SQLSTATE[22001] errors.
 *
 * Safe: purely expands column capacity, no data is lost or altered.
 * Runs on both the central DB and the tenant DB.
 */
return new class extends Migration
{
    private array $tables = [
        'sync_records'   => ['record_uuid'],
        'sync_conflicts' => ['record_uuid'],
    ];

    public function up(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            foreach ($this->tables as $table => $columns) {
                try {
                    $schema = Schema::connection($connection);
                    if (! $schema->hasTable($table)) {
                        continue;
                    }

                    $schema->table($table, function (Blueprint $bp) use ($columns) {
                        foreach ($columns as $col) {
                            $bp->string($col, 255)->nullable()->change();
                        }
                    });
                } catch (\Exception $e) {
                    // Skip connection when no database is configured (e.g. tenant during central migration)
                    continue;
                }
            }
        }
    }

    public function down(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            foreach ($this->tables as $table => $columns) {
                try {
                    $schema = Schema::connection($connection);
                    if (! $schema->hasTable($table)) {
                        continue;
                    }

                    $schema->table($table, function (Blueprint $bp) use ($columns) {
                        foreach ($columns as $col) {
                            // Revert to uuid (char 36) — note this will silently truncate composite keys
                            $bp->uuid($col)->nullable()->change();
                        }
                    });
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
    }
};
