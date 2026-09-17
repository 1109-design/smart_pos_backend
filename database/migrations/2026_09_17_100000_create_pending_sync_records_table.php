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

                if ($schema->hasTable('pending_sync_records')) {
                    continue;
                }

                $schema->create('pending_sync_records', function (Blueprint $table) {
                    $table->id();
                    $table->string('business_id')->nullable()->index();
                    $table->unsignedBigInteger('device_id')->nullable();
                    $table->uuid('acting_user_id')->nullable();
                    $table->string('table_name');
                    $table->string('record_uuid', 255);
                    $table->string('operation');
                    $table->json('payload')->nullable();
                    $table->timestamp('source_updated_at')->nullable();
                    $table->unsignedInteger('attempts')->default(0);
                    $table->boolean('failed_permanently')->default(false);
                    $table->text('last_error')->nullable();
                    $table->timestamp('last_attempt_at')->nullable();
                    $table->timestamps();

                    $table->index(['business_id', 'failed_permanently'], 'pending_sync_records_business_status_idx');
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
                if ($schema->hasTable('pending_sync_records')) {
                    $schema->drop('pending_sync_records');
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }
};
