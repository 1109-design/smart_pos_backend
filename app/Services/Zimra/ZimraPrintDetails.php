<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;

/**
 * Everything a till needs to print a receipt the way ZIMRA requires (FDMS spec
 * §10: taxpayer, buyer, receipt and verification blocks), taken from the receipt
 * ZIMRA accepted and the device's GetConfig. Printing from these, not from the
 * document, keeps the print identical to what was declared: the currency, the
 * amounts and the tax split are ZIMRA's.
 */
class ZimraPrintDetails
{
    /**
     * @return array<string, mixed>|null Null until ZIMRA has accepted the document.
     */
    public static function forDocument(FiscalDocument $document): ?array
    {
        $sale = ZimraSale::where($document->zimraKeyColumn(), $document->id)->first();

        return $sale ? self::forSale($sale) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forSale(ZimraSale $sale): ?array
    {
        $receipt = $sale->submitted_receipt;
        if (! $sale->isFiscalised() || $sale->isRejectedByZimra() || ! is_array($receipt) || ! $sale->zimra_qr_code) {
            return null;
        }

        $device = ZimraDevice::where('device_id', (string) $sale->device_id)->first();
        $config = (array) ($device?->fdms_config ?? []);
        $taxNames = collect($device?->applicable_taxes ?? [])->pluck('taxName', 'taxID');
        $type = (string) ($receipt['receiptType'] ?? 'FiscalInvoice');
        $isVatPayer = filled($config['vatNumber'] ?? null);

        return [
            'title' => match ($type) {
                'CreditNote' => 'CREDIT NOTE',
                'DebitNote' => 'DEBIT NOTE',
                default => $isVatPayer ? 'FISCAL TAX INVOICE' : 'FISCAL INVOICE',
            },
            'receipt_type' => $type,
            'seller' => [
                'name' => $config['taxPayerName'] ?? null,
                'tin' => $config['taxPayerTIN'] ?? $device?->tin,
                'vat_number' => $config['vatNumber'] ?? null,
                'branch_name' => $config['deviceBranchName'] ?? null,
                'address' => self::address($config['deviceBranchAddress'] ?? null),
                'email' => $config['deviceBranchContacts']['email'] ?? null,
                'phone' => $config['deviceBranchContacts']['phoneNo'] ?? null,
            ],
            'buyer' => self::buyer($receipt['buyerData'] ?? null),
            'invoice_no' => $receipt['invoiceNo'] ?? null,
            'receipt_counter' => $receipt['receiptCounter'] ?? null,
            'receipt_global_no' => $receipt['receiptGlobalNo'] ?? null,
            'fiscal_day_no' => $sale->fiscal_day_no,
            'device_id' => (string) $sale->device_id,
            'device_serial_no' => $config['deviceSerialNo'] ?? $device?->device_serial_no,
            'receipt_date' => $receipt['receiptDate'] ?? null,
            'currency' => $receipt['receiptCurrency'] ?? null,
            'lines' => array_map(fn (array $line) => [
                'name' => $line['receiptLineName'] ?? '',
                'hs_code' => $line['receiptLineHSCode'] ?? null,
                'quantity' => (float) ($line['receiptLineQuantity'] ?? 0),
                'unit_price' => (float) ($line['receiptLinePrice'] ?? 0),
                'total' => (float) ($line['receiptLineTotal'] ?? 0),
                'tax_percent' => isset($line['taxPercent']) ? (float) $line['taxPercent'] : null,
                'tax_name' => $taxNames[$line['taxID'] ?? null] ?? null,
                'vat' => isset($line['taxPercent'])
                    ? round((float) $line['receiptLineTotal'] * (float) $line['taxPercent'] / (100 + (float) $line['taxPercent']), 2)
                    : null,
            ], $receipt['receiptLines'] ?? []),
            'taxes' => array_map(fn (array $tax) => [
                'tax_name' => $taxNames[$tax['taxID'] ?? null] ?? null,
                'tax_percent' => isset($tax['taxPercent']) ? (float) $tax['taxPercent'] : null,
                'net' => round((float) $tax['salesAmountWithTax'] - (float) $tax['taxAmount'], 2),
                'vat' => (float) $tax['taxAmount'],
                'amount' => (float) $tax['salesAmountWithTax'],
            ], $receipt['receiptTaxes'] ?? []),
            'total' => (float) ($receipt['receiptTotal'] ?? 0),
            'payments' => array_map(fn (array $payment) => [
                'money_type' => $payment['moneyTypeCode'] ?? null,
                'amount' => (float) ($payment['paymentAmount'] ?? 0),
            ], $receipt['receiptPayments'] ?? []),
            'notes' => $receipt['receiptNotes'] ?? null,
            'referenced' => self::referenced($receipt['creditDebitNote'] ?? null, $type),
            'verification_code' => self::verificationCode((string) $sale->zimra_qr_code),
            'verification_url' => self::verificationUrl($config, (string) $sale->zimra_qr_code),
            'qr_code' => $sale->zimra_qr_code,
        ];
    }

    /** The QR's last 16 characters, in groups of four (spec §10.3 [46]). */
    public static function verificationCode(string $qrCode): ?string
    {
        $code = strtoupper(substr($qrCode, -16));

        return strlen($code) === 16 ? implode('-', str_split($code, 4)) : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function verificationUrl(array $config, string $qrCode): ?string
    {
        if (filled($config['qrUrl'] ?? null)) {
            return rtrim((string) $config['qrUrl'], '/');
        }

        $parts = parse_url($qrCode);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'] : null;
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return array<string, string|null>|null
     */
    private static function address(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return [
            'house_no' => $address['houseNo'] ?? null,
            'street' => $address['street'] ?? null,
            'city' => $address['city'] ?? null,
            'province' => $address['province'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $buyer
     * @return array<string, mixed>|null
     */
    private static function buyer(?array $buyer): ?array
    {
        if (! $buyer) {
            return null;
        }

        return [
            'register_name' => $buyer['buyerRegisterName'] ?? null,
            'trade_name' => $buyer['buyerTradeName'] ?? null,
            'tin' => $buyer['buyerTIN'] ?? null,
            'vat_number' => $buyer['VATNumber'] ?? null,
            'address' => self::address($buyer['buyerAddress'] ?? null),
            'email' => $buyer['buyerContacts']['email'] ?? null,
            'phone' => $buyer['buyerContacts']['phoneNo'] ?? null,
        ];
    }

    /**
     * The receipt a credit or debit note is against (spec §10.3 [24]-[28]).
     *
     * @param  array<string, mixed>|null  $reference
     * @return array<string, mixed>|null
     */
    private static function referenced(?array $reference, string $type): ?array
    {
        if (! $reference) {
            return null;
        }

        $original = isset($reference['receiptID'])
            ? ZimraSale::where('zimra_receipt_id', (string) $reference['receiptID'])->first()
            : ZimraSale::where('device_id', (string) ($reference['deviceID'] ?? ''))
                ->where('receipt_global_no', $reference['receiptGlobalNo'] ?? null)
                ->first();
        $originalReceipt = (array) ($original?->submitted_receipt ?? []);
        $originalDevice = $original ? ZimraDevice::where('device_id', (string) $original->device_id)->first() : null;

        return [
            'label' => $type === 'DebitNote' ? 'Debited invoice' : 'Credited invoice',
            'device_serial_no' => $originalDevice?->fdms_config['deviceSerialNo'] ?? $originalDevice?->device_serial_no,
            'receipt_global_no' => $originalReceipt['receiptGlobalNo'] ?? $reference['receiptGlobalNo'] ?? null,
            'receipt_date' => $originalReceipt['receiptDate'] ?? null,
            'invoice_no' => $originalReceipt['invoiceNo'] ?? null,
        ];
    }
}
