<?php

namespace App\Models;

use App\Events\CreditNoteChanged;
use App\Services\Zimra\FiscalDocument;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CreditNote extends Model implements FiscalDocument
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'invoice_id', 'customer_id', 'credit_note_number',
        'reason', 'subtotal', 'tax_total', 'total', 'created_by_user_id',
        'fiscal_status', 'fiscal_error', 'fiscal_receipt_number', 'fiscal_qr_code',
    ];

    protected static function booted(): void
    {
        static::created(function (CreditNote $creditNote): void {
            if (! $creditNote->business_id) {
                return;
            }

            CreditNoteChanged::dispatch($creditNote->business_id, $creditNote->id);
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function fiscalNumber(): string
    {
        return $this->credit_note_number ?: (string) $this->id;
    }

    public function zimraKeyColumn(): string
    {
        return 'credit_note_id';
    }

    public function wantsFiscalisation(): bool
    {
        return true;
    }

    public function fiscalLocationId(): ?string
    {
        return $this->invoice?->location_id;
    }

    /** ZIMRA requires the original invoice's currency (RCPT042). */
    public function fiscalCurrency(): string
    {
        return $this->invoice?->fiscalCurrency() ?? 'USD';
    }

    public function fiscalLines(): Collection
    {
        $rate = $this->invoice?->fiscalExchangeRate() ?? 1.0;

        return $this->items()->orderBy('created_at')->get()->map(fn (CreditNoteItem $item) => (object) [
            'quantity' => $item->quantity,
            'line_total' => (float) $item->line_total * $rate,
            'tax_amount' => (float) $item->tax_amount * $rate,
            'product_name' => $item->product_name,
        ])->toBase();
    }

    /** Taken off what the customer owes on account. */
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
            && abs($items->sum(fn (CreditNoteItem $item) => (float) $item->line_total) - (float) $this->total) <= $tolerance
            && abs($items->sum(fn (CreditNoteItem $item) => (float) $item->tax_amount) - (float) $this->tax_total) <= $tolerance;
    }

    public function fiscalSyncPayload(): array
    {
        return [
            'fiscal_only' => true,
            'business_id' => $this->business_id,
            'invoice_id' => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'credit_note_number' => $this->credit_note_number,
            'reason' => $this->reason,
            'subtotal' => (float) $this->subtotal,
            'tax_total' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'created_by_user_id' => $this->created_by_user_id,
            'fiscal_status' => $this->fiscal_status,
            'fiscal_error' => $this->fiscal_error,
            'fiscal_receipt_number' => $this->fiscal_receipt_number,
            'fiscal_qr_code' => $this->fiscal_qr_code,
        ];
    }
}
