<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            $schema = Schema::connection($connection);

            if (! $schema->hasTable('sync_records')) {
                continue;
            }

            if (! $schema->hasColumn('sync_records', 'source_updated_at')) {
                $schema->table('sync_records', function (Blueprint $table) {
                    $table->timestamp('source_updated_at')->nullable()->after('payload');
                    $table->index(['business_id', 'table_name', 'record_uuid', 'source_updated_at'], 'sync_records_version_idx');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            $schema = Schema::connection($connection);

            if (! $schema->hasTable('sync_records')) {
                continue;
            }

            if ($schema->hasColumn('sync_records', 'source_updated_at')) {
                $schema->table('sync_records', function (Blueprint $table) {
                    $table->dropIndex('sync_records_version_idx');
                    $table->dropColumn('source_updated_at');
                });
            }
        }
    }
};
