<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetCut extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'sheet_lot_id', 'width', 'height', 'area',
        'transaction_id', 'user_id', 'cut_at',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'decimal:4',
            'height' => 'decimal:4',
            'area' => 'decimal:4',
            'cut_at' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(SheetLot::class, 'sheet_lot_id');
    }
}
