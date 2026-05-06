<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('sync_records', function (Blueprint $table) {
            $table->id();
            $table->string('table_name');
            $table->uuid('record_uuid');
            $table->enum('operation', ['upsert', 'delete']);
            $table->json('payload')->nullable();
            $table->timestamp('synced_at');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->boolean('conflict_resolved')->default(false);
            $table->timestamps();

            $table->index(['table_name', 'record_uuid']);
            $table->index('synced_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sync_records');
    }
};
