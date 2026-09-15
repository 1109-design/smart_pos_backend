<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GLS·02 — append-only financial/audit record of dimensional material that
 * left inventory without being sold. 'cutting_waste' is auto-recorded by the
 * cutting engine (a guillotine remainder below the product's minimum usable
 * size) and needs no approval; 'scrap'/'breakage' are operator-raised and
 * only executed once approved — see ApprovalRequest's generic polymorphic
 * flow (subject_type 'SheetLot' or a scrap request) on the Flutter side.
 * Never deleted or mutated after creation — see SyncProcessor::IMMUTABLE.
 */
class SheetLossRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id', 'business_id', 'sheet_lot_id', 'product_id', 'kind', 'reason',
        'width', 'height', 'area', 'unit_cost', 'financial_impact', 'notes',
        'photo_path', 'approval_request_id', 'reported_by_user_id',
        'approved_by_user_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'decimal:4',
            'height' => 'decimal:4',
            'area' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'financial_impact' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(SheetLot::class, 'sheet_lot_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
