<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Fix sync_conflicts.reason column — VARCHAR(255) → TEXT
 * Needed because server exception messages (which can include full JSON payloads)
 * easily exceed 255 characters and cause SQLSTATE[22001] truncation errors.
 *
 * Safe: expands the column, no data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            try {
                $schema = Schema::connection($connection);

                if (! $schema->hasTable('sync_conflicts')) {
                    continue;
                }

                // Check current column type — only alter if it's still a string/varchar
                $schema->table('sync_conflicts', function (Blueprint $table) {
                    $table->text('reason')->nullable()->change();
                });
            } catch (\Exception $e) {
                // Skip connection when no database is selected
                continue;
            }
        }
    }

    public function down(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            try {
                $schema = Schema::connection($connection);

                if (! $schema->hasTable('sync_conflicts')) {
                    continue;
                }

                $schema->table('sync_conflicts', function (Blueprint $table) {
                    $table->string('reason')->nullable()->change();
                });
            } catch (\Exception $e) {
                continue;
            }
        }
    }
};
