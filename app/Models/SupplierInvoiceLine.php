<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'supplier_invoice_id', 'purchase_order_item_id', 'grv_item_id',
        'product_id', 'description', 'quantity', 'unit_cost', 'discount_pct',
        'tax_rate_id', 'gl_account_id', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount_pct' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function grvItem(): BelongsTo
    {
        return $this->belongsTo(GrvItem::class, 'grv_item_id');
    }
}
