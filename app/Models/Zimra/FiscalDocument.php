<?php

namespace App\Models\Zimra;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a device asks ZIMRA to fiscalise besides a till sale (Phase
 * 11.2): a customer invoice, or a credit / debit note against a fiscalised
 * sale or invoice. Lines and totals carry ZIMRA's signs — negative for a
 * credit note. The status and fiscal fields are the server's alone.
 */
class FiscalDocument extends Model
{
    use HasUuids;

    public const TYPES = ['FiscalInvoice', 'CreditNote', 'DebitNote'];

    protected $fillable = [
        'id', 'business_id', 'location_id', 'document_type', 'source_type', 'source_id',
        'document_number', 'original_transaction_id', 'original_document_id', 'customer_id',
        'currency_code', 'total', 'lines', 'payments', 'reason', 'status',
        'fiscal_receipt_id', 'fiscal_qr_code', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'payments' => 'array',
            'total' => 'float',
        ];
    }
}
