<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('sync_records', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('sync_records', 'sync_error')) {
                $table->text('sync_error')->nullable()->after('conflict_resolved');
            }
            if (! Schema::connection('tenant')->hasColumn('sync_records', 'retry_count')) {
                $table->unsignedSmallInteger('retry_count')->default(0)->after('sync_error');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sync_records', function (Blueprint $table) {
            $table->dropColumn(['sync_error', 'retry_count']);
        });
    }
};
