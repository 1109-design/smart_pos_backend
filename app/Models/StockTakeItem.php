<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockTakeItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'stock_take_id', 'product_id', 'product_name',
        'system_qty', 'counted_qty', 'notes',
        'flagged_for_recount', 'recount_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'flagged_for_recount' => 'boolean',
            'recount_completed_at' => 'datetime',
        ];
    }

    /**
     * True only while this item has been flagged for a variance-threshold
     * recount AND that recount hasn't happened yet — the one condition
     * StockTakesController::approve() needs to gate on.
     */
    public function needsRecount(): bool
    {
        return $this->flagged_for_recount && $this->recount_completed_at === null;
    }
}
