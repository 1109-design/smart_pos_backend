<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'supplier_id', 'grv_id', 'invoice_number',
        'invoice_date', 'amount', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function grv(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedVoucher::class, 'grv_id');
    }
}
