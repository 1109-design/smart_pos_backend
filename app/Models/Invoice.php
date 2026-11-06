<?php

namespace App\Models;

use App\Events\InvoiceChanged;
use App\Services\Zimra\FiscalDocument;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Invoice extends Model implements FiscalDocument
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'customer_id', 'quotation_id',
        'invoice_number', 'type', 'status', 'issue_date', 'due_date', 'payment_terms_days',
        'subtotal', 'discount_total', 'tax_total', 'deposit_required', 'total', 'amount_paid',
        'recurring_schedule_id', 'notes', 'created_by_user_id',
        'currency_code', 'exchange_rate',
        'fiscal_status', 'fiscal_error', 'fiscal_receipt_number', 'fiscal_qr_code',
    ];

    protected static function booted(): void
    {
        $dispatch = function (Invoice $invoice): void {
            if (! $invoice->business_id) {
                return;
            }

            InvoiceChanged::dispatch($invoice->business_id, $invoice->location_id, $invoice->id);
        };

        static::created($dispatch);
        static::updated($dispatch);
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function recurringSchedule(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoiceSchedule::class, 'recurring_schedule_id');
    }

    /**
     * Sent to ZIMRA once issued, unless the document isn't a new taxable supply:
     * a pro-forma isn't a tax document, an invoice from receipts covers till
     * sales already declared, and an opening balance is an old debt.
     */
    public function isFiscalisable(): bool
    {
        return ! in_array($this->type, ['pro_forma', 'from_receipts'], true)
            && ! str_starts_with((string) $this->notes, 'Opening Balance:');
    }

    public function fiscalNumber(): string
    {
        return $this->invoice_number ?: (string) $this->id;
    }

    public function zimraKeyColumn(): string
    {
        return 'invoice_id';
    }

    public function wantsFiscalisation(): bool
    {
        return true;
    }

    public function fiscalLocationId(): ?string
    {
        return $this->location_id;
    }

    /** The currency the customer's invoice is printed in. */
    public function fiscalCurrency(): string
    {
        return $this->currency_code ?: (Business::find($this->business_id)?->currency_code ?: 'USD');
    }

    /** Amounts are stored in base currency; the print multiplies by this rate. */
    public function fiscalExchangeRate(): float
    {
        return (float) $this->exchange_rate ?: 1.0;
    }

    public function fiscalLines(): Collection
    {
        $rate = $this->fiscalExchangeRate();

        return $this->items()->orderBy('created_at')->get()->map(fn (InvoiceItem $item) => (object) [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'line_total' => (float) $item->line_total * $rate,
            'tax_amount' => (float) $item->tax_amount * $rate,
            'product_name' => $item->product_name,
        ])->toBase();
    }

    /** Issued on account: later payments against it aren't new receipts. */
    public function fiscalPayments(): Collection
    {
        return collect([(object) ['method' => 'credit', 'amount' => 1, 'base_equivalent' => 1]]);
    }

    public function fiscalCustomerId(): ?string
    {
        return $this->customer_id;
    }

    public function fiscalDefaultMoneyType(): string
    {
        return 'Credit';
    }

    public function fiscalPrintForm(): string
    {
        return 'InvoiceA4';
    }

    public function fiscalReadyToSend(): bool
    {
        $items = $this->items()->get();
        $tolerance = max(0.02, $items->count() * 0.005);

        return $items->isNotEmpty()
            && abs($items->sum(fn (InvoiceItem $item) => (float) $item->line_total) - (float) $this->total) <= $tolerance
            && abs($items->sum(fn (InvoiceItem $item) => (float) $item->tax_amount) - (float) $this->tax_total) <= $tolerance;
    }

    public function fiscalSyncPayload(): array
    {
        return [
            // Tells a till that holds this invoice to take only the fiscal
            // columns, so this can't overwrite a payment it hasn't pushed yet.
            'fiscal_only' => true,
            'business_id' => $this->business_id,
            'location_id' => $this->location_id,
            'customer_id' => $this->customer_id,
            'quotation_id' => $this->quotation_id,
            'invoice_number' => $this->invoice_number,
            'type' => $this->type,
            'status' => $this->status,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'payment_terms_days' => (int) $this->payment_terms_days,
            'subtotal' => (float) $this->subtotal,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'deposit_required' => (float) $this->deposit_required,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'recurring_schedule_id' => $this->recurring_schedule_id,
            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'currency_code' => $this->currency_code,
            'exchange_rate' => (float) $this->exchange_rate,
            'fiscal_status' => $this->fiscal_status,
            'fiscal_error' => $this->fiscal_error,
            'fiscal_receipt_number' => $this->fiscal_receipt_number,
            'fiscal_qr_code' => $this->fiscal_qr_code,
        ];
    }
}
