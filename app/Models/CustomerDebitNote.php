<?php

namespace App\Models;

use App\Services\Zimra\FiscalDocument;
use App\Services\Zimra\ZimraPrintDetails;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A charge added to an invoice after it was issued. Sent to ZIMRA as a debit
 * note against the invoice's receipt, in that receipt's currency (RCPT042).
 */
class CustomerDebitNote extends Model implements FiscalDocument
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'is_gl_posted' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerDebitNoteItem::class);
    }

    public function fiscalNumber(): string
    {
        return $this->debit_note_number ?: (string) $this->id;
    }

    public function zimraKeyColumn(): string
    {
        return 'customer_debit_note_id';
    }

    /** A draft or cancelled note is not a document the customer was given. */
    public function wantsFiscalisation(): bool
    {
        return ! in_array($this->status, ['draft', 'cancelled'], true);
    }

    public function fiscalLocationId(): ?string
    {
        return $this->invoice?->location_id;
    }

    public function fiscalCurrency(): string
    {
        return $this->invoice?->fiscalCurrency() ?? ($this->currency_code ?: 'USD');
    }

    public function fiscalLines(): Collection
    {
        $rate = $this->invoice?->fiscalExchangeRate() ?? ((float) $this->exchange_rate ?: 1.0);

        return $this->items()->orderBy('created_at')->get()->map(fn (CustomerDebitNoteItem $item) => (object) [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'line_total' => (float) $item->line_total * $rate,
            'tax_amount' => (float) $item->tax_amount * $rate,
            'product_name' => $item->description,
        ])->toBase();
    }

    /** Added to what the customer owes on account. */
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
            && abs($items->sum(fn (CustomerDebitNoteItem $item) => (float) $item->line_total) - (float) $this->total) <= $tolerance
            && abs($items->sum(fn (CustomerDebitNoteItem $item) => (float) $item->tax_amount) - (float) $this->tax_total) <= $tolerance;
    }

    public function fiscalSyncPayload(): array
    {
        return [
            'fiscal_only' => true,
            'business_id' => $this->business_id,
            'invoice_id' => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'debit_note_number' => $this->debit_note_number,
            'fiscal_status' => $this->fiscal_status,
            'fiscal_error' => $this->fiscal_error,
            'fiscal_receipt_number' => $this->fiscal_receipt_number,
            'fiscal_qr_code' => $this->fiscal_qr_code,
            'fiscal_details' => ZimraPrintDetails::forDocument($this),
        ];
    }
}
