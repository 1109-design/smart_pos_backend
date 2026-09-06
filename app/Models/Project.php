<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'reference', 'notes', 'budget',
        'status', 'created_by_user_id', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:4',
            'closed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class, 'project_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'project_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
