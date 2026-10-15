<?php

namespace App\Services\Zimra;

use App\Models\Customer;
use App\Models\Transaction;
use Closure;

/**
 * What one ZIMRA submission is about — a till sale, or a fiscal document
 * (a customer invoice, credit note or debit note; Phase 11.2) — in the
 * shape the device-locked submission needs.
 */
final class FiscalSubject
{
    /**
     * @param  Transaction  $shape  the sale (or an unsaved stand-in carrying the currency) the formatter reads
     * @param  iterable<object>  $items  each with product_name, quantity, line_total, tax_amount
     * @param  iterable<object>  $payments  each with method and amount (positive)
     * @param  array<string, mixed>|null  $creditDebitNote  the receipt a note credits / debits
     * @param  Closure(string, ?string=, ?string=, ?string=): void  $setStatus  records pending / failed / fiscalised (receipt id, QR) / error
     */
    public function __construct(
        public readonly string $label,
        public readonly Transaction $shape,
        public readonly iterable $items,
        public readonly iterable $payments,
        public readonly ?Customer $customer,
        public readonly string $receiptType,
        public readonly ?string $currency,
        public readonly ?array $creditDebitNote,
        public readonly ?string $receiptNotes,
        public readonly Closure $setStatus,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formatterOptions(): array
    {
        return array_filter([
            'receiptType' => $this->receiptType,
            'currency' => $this->currency,
            'creditDebitNote' => $this->creditDebitNote,
            'receiptNotes' => $this->receiptNotes,
        ], fn ($v) => $v !== null);
    }
}
