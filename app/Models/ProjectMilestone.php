<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMilestone extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'project_id', 'title', 'target_date',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // No DB-level cascade on milestone_tasks — a deleted milestone would
        // otherwise leave its tasks orphaned (still readable by their raw
        // milestone_id, never reachable through a project again).
        static::deleting(function (ProjectMilestone $milestone) {
            $milestone->tasks()->delete();
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(MilestoneTask::class, 'milestone_id');
    }

    /**
     * Always derived from the checklist, never stored — a milestone with no
     * tasks yet reads as 0%, not un-set or 100%.
     */
    public function percentComplete(): int
    {
        $total = $this->tasks->count();

        if ($total === 0) {
            return 0;
        }

        return (int) round($this->tasks->where('is_done', true)->count() / $total * 100);
    }

    public function isOverdue(): bool
    {
        return $this->target_date !== null
            && $this->target_date->isPast()
            && $this->percentComplete() < 100;
    }
}
