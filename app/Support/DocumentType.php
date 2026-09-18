<?php

namespace App\Support;

/**
 * Centralized registry of print/PDF document types the document branding
 * system covers. Adding a future type (credit note, delivery note, GRN...)
 * means adding one constant + one entry in all() — the branding engine
 * itself (DocumentBrandingSetting, BusinessBrandingService, the Flutter
 * renderer) is generic over document_type and needs no other change.
 */
class DocumentType
{
    const SALES_RECEIPT = 'sales_receipt';

    const INVOICE = 'invoice';

    const QUOTATION = 'quotation';

    const REQUISITION = 'requisition';

    const GRV = 'grv';

    const DELIVERY_NOTE = 'delivery_note';

    // AP module — see the Flutter DocumentType enum's identical additions.
    const SUPPLIER_INVOICE = 'supplier_invoice';

    const SUPPLIER_CREDIT_NOTE = 'supplier_credit_note';

    const PAYMENT_VOUCHER = 'payment_voucher';

    const SUPPLIER_STATEMENT = 'supplier_statement';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SALES_RECEIPT,
            self::INVOICE,
            self::QUOTATION,
            self::REQUISITION,
            self::GRV,
            self::DELIVERY_NOTE,
            self::SUPPLIER_INVOICE,
            self::SUPPLIER_CREDIT_NOTE,
            self::PAYMENT_VOUCHER,
            self::SUPPLIER_STATEMENT,
        ];
    }

    /** Sensible paper size when a business hasn't overridden document_branding_settings.paper_size. */
    public static function defaultPaperSize(string $documentType): string
    {
        return $documentType === self::SALES_RECEIPT ? '80mm' : 'A4';
    }
}
