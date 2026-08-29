<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'quotation_id', 'product_id', 'product_name',
        'quantity', 'unit_price', 'discount_pct', 'tax_rate_id',
        'line_total', 'invoiced_quantity',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
