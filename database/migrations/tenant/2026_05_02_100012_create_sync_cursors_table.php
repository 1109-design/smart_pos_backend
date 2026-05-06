<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('table_name');
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'table_name']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sync_cursors');
    }
};
