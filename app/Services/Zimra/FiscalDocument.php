<?php

namespace App\Services\Zimra;

use Illuminate\Support\Collection;

/**
 * Anything sent to ZIMRA as a receipt: a till sale or refund, a customer
 * invoice, or a credit note on one. Implemented by Eloquent models holding the
 * fiscal_status, fiscal_error, fiscal_receipt_number and fiscal_qr_code columns.
 */
interface FiscalDocument
{
    public function fiscalNumber(): string;

    /** The zimra_sales column that points at this kind of document. */
    public function zimraKeyColumn(): string;

    public function wantsFiscalisation(): bool;

    public function fiscalLocationId(): ?string;

    public function fiscalCurrency(): string;

    /**
     * Tax-inclusive line totals in the receipt's currency.
     *
     * @return Collection<int, object{quantity: mixed, line_total: mixed, tax_amount: mixed, product_name: mixed}>
     */
    public function fiscalLines(): Collection;

    /**
     * @return Collection<int, object{method: string, amount: mixed, base_equivalent: mixed}>
     */
    public function fiscalPayments(): Collection;

    public function fiscalCustomerId(): ?string;

    /** Used when a document has no payment rows at all. */
    public function fiscalDefaultMoneyType(): string;

    public function fiscalPrintForm(): string;

    /** False while a later sync batch still has lines to deliver. */
    public function fiscalReadyToSend(): bool;

    /**
     * What the tills are sent. Built from plain literals: decimal casts render
     * as strings, which break the app's `as num?` on pull.
     *
     * @return array<string, mixed>
     */
    public function fiscalSyncPayload(): array;
}
