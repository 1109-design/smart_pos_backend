<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalRuleSet extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'process', 'name', 'description', 'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(ApprovalRule::class, 'rule_set_id');
    }
}
