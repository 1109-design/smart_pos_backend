<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central approval stage engine: named-approver groups, per-stage/process
     * delegation scoping, and a per-stage audit trail. See the "Central
     * Approval Stage Engine" plan — approval_groups/approval_group_members
     * already existed as dormant scaffolding (created by
     * 2026_09_13_200000_create_approval_rule_engine_tables) with zero
     * production usage, so approval_group_members is safe to recreate here
     * with a uuid primary key matching every other client-synced table
     * instead of its original bigint auto-increment.
     */
    public function up(): void
    {
        Schema::dropIfExists('approval_group_members');

        Schema::create('approval_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('group_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('approval_groups')->onDelete('cascade');
            $table->unique(['group_id', 'user_id']);
        });

        Schema::table('approval_delegations', function (Blueprint $table) {
            // Null = wildcard (matches every process/level) — kept for the
            // pre-existing dormant delegation rows; the new self-service
            // delegation screen always sets both, scoping a delegation to a
            // specific process/stage rather than a blanket hand-off.
            $table->string('process', 100)->nullable()->after('delegate_user_id');
            $table->integer('level')->nullable()->after('process');
        });

        Schema::create('approval_request_stage_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('approval_request_id');
            $table->integer('level');
            $table->string('decision', 20);
            $table->uuid('acted_by_user_id');
            $table->uuid('acted_as_delegate_for_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('sla_breached')->default(false);
            $table->boolean('same_approver_as_prior_stage')->default(false);
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->foreign('approval_request_id')->references('id')->on('approval_requests')->onDelete('cascade');
            $table->index(['business_id', 'approval_request_id'], 'approval_stage_decisions_business_request_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_request_stage_decisions');

        Schema::table('approval_delegations', function (Blueprint $table) {
            $table->dropColumn(['process', 'level']);
        });

        Schema::dropIfExists('approval_group_members');

        Schema::create('approval_group_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('approval_groups')->onDelete('cascade');
            $table->unique(['group_id', 'user_id']);
        });
    }
};
