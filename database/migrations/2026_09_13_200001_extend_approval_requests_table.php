<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->uuid('rule_set_id')->nullable();
            $table->integer('current_level')->default(1);
            $table->integer('max_level')->default(1);
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->uuid('escalated_to_user_id')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->decimal('estimated_value', 20, 2)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->boolean('is_delegated')->default(false);
            $table->uuid('delegated_from_user_id')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn([
                'rule_set_id',
                'current_level',
                'max_level',
                'sla_due_at',
                'escalated_at',
                'escalated_to_user_id',
                'priority',
                'estimated_value',
                'branch_id',
                'is_delegated',
                'delegated_from_user_id',
                'rejection_reason',
            ]);
        });
    }
};
