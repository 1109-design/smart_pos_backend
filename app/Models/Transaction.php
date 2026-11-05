<?php

namespace App\Models;

use App\Events\TransactionRecorded;
use App\Services\Zimra\FiscalDocument;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Transaction extends Model implements FiscalDocument
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'user_id', 'customer_id', 'client_name',
        'subtotal', 'tax_total', 'discount_total', 'deposit_total',
        'surcharge_total', 'total',
        'base_currency', 'status', 'sale_number', 'notes', 'void_reason',
        'fiscal_status', 'fiscal_receipt_number', 'fiscal_qr_code', 'fiscal_error', 'fiscalisation_requested',
    ];

    protected static function booted(): void
    {
        static::created(function (Transaction $transaction): void {
            if (! $transaction->business_id) {
                return;
            }

            TransactionRecorded::dispatch($transaction->business_id, $transaction->location_id, $transaction->id);
        });

        static::updated(function (Transaction $transaction): void {
            if (! $transaction->wasChanged('status') || ! $transaction->business_id) {
                return;
            }

            TransactionRecorded::dispatch($transaction->business_id, $transaction->location_id, $transaction->id);
        });
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'deposit_total' => 'decimal:4',
            'surcharge_total' => 'decimal:4',
            'total' => 'decimal:4',
            'fiscalisation_requested' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function fiscalNumber(): string
    {
        return $this->sale_number ?: (string) $this->id;
    }

    public function zimraKeyColumn(): string
    {
        return 'transaction_id';
    }

    public function wantsFiscalisation(): bool
    {
        return (bool) $this->fiscalisation_requested;
    }

    public function fiscalLocationId(): ?string
    {
        return $this->location_id;
    }

    public function fiscalCurrency(): string
    {
        return $this->base_currency ?: 'USD';
    }

    public function fiscalLines(): Collection
    {
        return TransactionItem::where('transaction_id', $this->id)->get()->toBase();
    }

    public function fiscalPayments(): Collection
    {
        return Payment::where('transaction_id', $this->id)->get()->toBase();
    }

    public function fiscalCustomerId(): ?string
    {
        return $this->customer_id;
    }

    public function fiscalDefaultMoneyType(): string
    {
        return $this->status === 'credit_sale' ? 'Credit' : 'Cash';
    }

    public function fiscalPrintForm(): string
    {
        return 'Receipt48';
    }

    public function fiscalReadyToSend(): bool
    {
        return true;
    }

    public function fiscalSyncPayload(): array
    {
        return [
            'business_id' => $this->business_id,
            'location_id' => $this->location_id,
            'user_id' => $this->user_id,
            'customer_id' => $this->customer_id,
            'client_name' => $this->client_name,
            'fiscalisation_requested' => (bool) $this->fiscalisation_requested,
            'subtotal' => (float) $this->subtotal,
            'tax_total' => (float) $this->tax_total,
            'discount_total' => (float) $this->discount_total,
            'deposit_total' => (float) $this->deposit_total,
            'surcharge_total' => (float) $this->surcharge_total,
            'total' => (float) $this->total,
            'base_currency' => $this->base_currency,
            'status' => $this->status,
            'sale_number' => $this->sale_number,
            'notes' => $this->notes,
            'fiscal_status' => $this->fiscal_status,
            'fiscal_error' => $this->fiscal_error,
            'fiscal_receipt_number' => $this->fiscal_receipt_number,
            'fiscal_qr_code' => $this->fiscal_qr_code,
        ];
    }
}
