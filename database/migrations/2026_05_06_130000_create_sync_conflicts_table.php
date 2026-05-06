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

                if ($schema->hasTable('sync_conflicts')) {
                    continue;
                }
            } catch (\Exception $e) {
                // Skip tenant connection when no database is selected (e.g. during central migration)
                continue;
            }

            $schema->create('sync_conflicts', function (Blueprint $table) {
                $table->id();
                $table->string('business_id')->nullable()->index();
                $table->string('table_name');
                $table->uuid('record_uuid');
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('reason')->nullable();
                $table->string('conflict_type')->default('version_conflict');
                $table->json('local_payload')->nullable();
                $table->json('server_payload')->nullable();
                $table->string('status')->default('pending')->index();
                $table->uuid('resolved_by')->nullable();
                $table->string('resolution_action')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'table_name', 'record_uuid'], 'sync_conflicts_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ([null, 'tenant'] as $connection) {
            $schema = Schema::connection($connection);
            if ($schema->hasTable('sync_conflicts')) {
                $schema->drop('sync_conflicts');
            }
        }
    }
};
