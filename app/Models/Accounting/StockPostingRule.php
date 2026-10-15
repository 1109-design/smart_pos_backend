<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Which accounts a stock movement type posts to (Phase 6), optionally per
 * location and / or product category. Edited and applied on devices; the
 * server stores and relays it.
 */
class StockPostingRule extends Model
{
    use HasUuids;

    public const MOVEMENT_TYPES = [
        'GRV', 'GRV_NO_PO', 'SALE', 'RETURN', 'ADJ_POS', 'ADJ_NEG', 'WRITE_OFF',
        'DISPOSAL', 'TRANSFER_OUT', 'TRANSFER_IN', 'TAKE_ON', 'REVALUATION', 'REQUISITION_ISSUE',
    ];

    protected $fillable = [
        'id', 'business_id', 'movement_type', 'location_id', 'product_category_id',
        'debit_account_id', 'credit_account_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
