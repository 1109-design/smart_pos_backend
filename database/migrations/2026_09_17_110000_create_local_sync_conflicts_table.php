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

                if ($schema->hasTable('local_sync_conflicts')) {
                    continue;
                }

                $schema->create('local_sync_conflicts', function (Blueprint $table) {
                    $table->id();
                    $table->string('business_id')->index();
                    $table->unsignedBigInteger('device_id')->nullable();
                    $table->string('table_name');
                    $table->uuid('record_uuid');
                    $table->string('reason')->nullable();
                    $table->json('local_payload')->nullable();
                    $table->json('incoming_payload')->nullable();
                    $table->string('peer_device_name')->nullable();
                    $table->string('status')->default('pending');
                    $table->timestamp('occurred_at');
                    $table->timestamps();

                    // Dedup key for safe retry: the same device reporting
                    // the same conflict again (e.g. its previous report's
                    // response was lost) must not create a second row.
                    $table->unique(
                        ['device_id', 'table_name', 'record_uuid', 'occurred_at'],
                        'local_sync_conflicts_dedup_idx'
                    );
                    $table->index(['business_id', 'status'], 'local_sync_conflicts_business_status_idx');
                });
            } catch (Exception $e) {
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
                if ($schema->hasTable('local_sync_conflicts')) {
                    $schema->drop('local_sync_conflicts');
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }
};
