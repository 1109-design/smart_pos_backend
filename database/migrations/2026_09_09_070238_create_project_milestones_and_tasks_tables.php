<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRJ·04 follow-up — a milestone tracks progress on a project via its
     * own checklist of tasks. Percent complete is always derived (tasks
     * done ÷ total) at read time, never stored, so it can't drift out of
     * sync with the checklist. Both tables are child-scoped like
     * requisitions/requisition_items: no business_id of their own —
     * ownership resolves through project_id/milestone_id.
     */
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->index();
            $table->string('title');
            $table->date('target_date')->nullable();
            $table->timestamps();
        });

        Schema::create('milestone_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('milestone_id')->index();
            $table->string('title');
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestone_tasks');
        Schema::dropIfExists('project_milestones');
    }
};
