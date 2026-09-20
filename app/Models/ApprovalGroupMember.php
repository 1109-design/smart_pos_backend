<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalGroupMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'group_id', 'user_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ApprovalGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
