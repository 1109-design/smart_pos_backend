<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A cost centre (Phase 10.1). A split one ([is_split]) holds percentages
 * of others ([split]: list of {cost_centre_id, pct}) — the device divides
 * a posting line between them.
 */
class CostCentre extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'code', 'name', 'parent_id', 'is_split', 'split', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'split' => 'array',
            'is_split' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
