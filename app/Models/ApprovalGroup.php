<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'description',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ApprovalGroupMember::class, 'group_id');
    }
}
