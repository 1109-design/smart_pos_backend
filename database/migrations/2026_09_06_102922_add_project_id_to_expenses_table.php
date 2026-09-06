<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRJ·04 — expenses (transport bringing materials in, labour for
     * loading/offloading) are captured directly against the job. No FK:
     * `projects` and `expenses` are both plain UUID-keyed tenant tables
     * elsewhere in this schema too.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->after('recorded_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });
    }
};
