<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rule_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('process', 100);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'process']);
        });

        Schema::create('approval_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('rule_set_id');
            $table->integer('level')->default(1);
            $table->string('condition_type', 50)->nullable();
            $table->decimal('condition_value', 20, 4)->nullable();
            $table->decimal('condition_value_max', 20, 4)->nullable();
            $table->string('required_role', 100)->nullable();
            $table->uuid('approval_group_id')->nullable();
            $table->integer('min_approvers')->default(1);
            $table->boolean('is_sequential')->default(true);
            $table->integer('sla_hours')->default(24);
            $table->string('escalate_to_role', 100)->nullable();
            $table->integer('escalate_after_hours')->nullable();
            $table->boolean('require_different_user')->default(true);
            $table->timestamps();

            $table->foreign('rule_set_id')->references('id')->on('approval_rule_sets')->onDelete('cascade');
            $table->index(['business_id', 'level']);
        });

        Schema::create('approval_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_group_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id');
            $table->uuid('user_id');
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('approval_groups')->onDelete('cascade');
            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->uuid('delegator_user_id');
            $table->uuid('delegate_user_id');
            $table->text('reason')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'delegate_user_id']);
            $table->index(['business_id', 'delegator_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_group_members');
        Schema::dropIfExists('approval_groups');
        Schema::dropIfExists('approval_rules');
        Schema::dropIfExists('approval_rule_sets');
    }
};
