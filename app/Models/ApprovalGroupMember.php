<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalGroupMember extends Model
{
    protected $fillable = [
        'group_id', 'user_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ApprovalGroup::class, 'group_id');
    }
}
