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
        ];
    }

    /** Sensible paper size when a business hasn't overridden document_branding_settings.paper_size. */
    public static function defaultPaperSize(string $documentType): string
    {
        return $documentType === self::SALES_RECEIPT ? '80mm' : 'A4';
    }
}
