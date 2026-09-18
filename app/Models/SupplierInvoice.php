<?php

namespace App\Models;

use App\Events\SupplierInvoiceChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        $dispatch = function (SupplierInvoice $invoice): void {
            if (! $invoice->business_id) {
                return;
            }

            SupplierInvoiceChanged::dispatch($invoice->business_id, $invoice->supplier_id, $invoice->id, $invoice->status);
        };

        static::created($dispatch);
        static::updated($dispatch);
    }

    protected $fillable = [
        'id', 'business_id', 'supplier_id', 'grv_id', 'purchase_order_id',
        'invoice_number', 'invoice_date', 'due_date', 'currency_code',
        'exchange_rate', 'subtotal', 'discount_total', 'tax_total',
        'withholding_tax_total', 'other_charges_total', 'amount', 'status',
        'match_status', 'description', 'created_by_user_id',
        'approved_by_user_id', 'approved_at', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'withholding_tax_total' => 'decimal:4',
            'other_charges_total' => 'decimal:4',
            'amount' => 'decimal:4',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'supplier_invoice_id');
    }
}
