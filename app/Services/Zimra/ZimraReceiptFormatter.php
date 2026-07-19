<?php

namespace App\Services\Zimra;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Zimra\ZimraDevice;
use Illuminate\Support\Facades\Log;

/**
 * Formats a SmartPOS transaction into the ZIMRA FDMS receipt schema and signs
 * it with the device's private key (spec §13.2.1).
 *
 * POS prices are tax-inclusive: line_total is the gross amount the customer
 * paid and tax_amount is the VAT portion already inside it, so receipts are
 * submitted with receiptLinesTaxInclusive = true.
 */
class ZimraReceiptFormatter
{
    /**
     * Extract the VAT portion from a tax-inclusive amount.
     */
    private static function taxCalculator(float $saleAmount, float $taxRate): float
    {
        if ($taxRate <= 0.0 || $saleAmount <= 0.0) {
            return 0.0;
        }

        $preTax = $saleAmount / (1 + ($taxRate / 100));

        return round($saleAmount - $preTax, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * @param  iterable<TransactionItem>  $items
     * @param  iterable<Payment>  $payments
     */
    public static function formatReceipt(
        Transaction $transaction,
        iterable $items,
        iterable $payments,
        Business $business,
        ?Customer $customer,
        ZimraDevice $device,
        ?string $previousHash,
        int $receiptGlobalNo,
        int $receiptCounter,
        ?array $taxCodeMap,
        ?array $applicableTaxes,
        string $invoiceNo
    ): array {
        $receiptLines = [];
        $lineNo = 0;

        foreach ($items as $item) {
            $lineNo++;
            $quantity = (float) round((float) $item->quantity, 2);
            $lineTotal = (float) round((float) $item->line_total, 2);
            $taxAmount = (float) round((float) $item->tax_amount, 2);

            // Items store the VAT amount, not the rate — derive the inclusive rate.
            $netAmount = $lineTotal - $taxAmount;
            $taxRate = $netAmount > 0 ? round(($taxAmount / $netAmount) * 100, 2) : 0.0;

            $resolved = self::resolveTax($taxRate, $applicableTaxes);
            $taxId = $resolved['taxID'];
            $fdmsTaxPercent = $resolved['taxPercent'];

            // For TaxInclusive=true the receiptLinePrice MUST be the tax-inclusive
            // unit price or ZIMRA throws RCPT024 (line total != price × quantity).
            $inclusiveUnitPrice = $quantity > 0 ? round($lineTotal / $quantity, 6) : 0.0;

            $lineObj = [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => $lineNo,
                'receiptLineHSCode' => '04021099',
                'receiptLineName' => $item->product_name ?: 'Item',
                'receiptLinePrice' => $inclusiveUnitPrice,
                'receiptLineQuantity' => $quantity,
                'receiptLineTotal' => $lineTotal,
                'taxID' => $taxId,
            ];

            // Only include taxPercent for non-exempt lines, using the FDMS value
            // so it matches the device config exactly.
            if ($taxId !== 1 && $fdmsTaxPercent !== null) {
                $lineObj['taxPercent'] = (float) round($fdmsTaxPercent, 2);
            }

            // taxCode is optional — an empty string causes RCPT025.
            $taxCodeValue = $taxCodeMap[$taxId] ?? '';
            if ($taxCodeValue !== '') {
                $lineObj['taxCode'] = $taxCodeValue;
            }

            $receiptLines[] = $lineObj;
        }

        // ── Totals ───────────────────────────────────────────────────────────
        $receiptTotal = 0.0;
        foreach ($receiptLines as $line) {
            $receiptTotal += $line['receiptLineTotal'];
        }
        $receiptTotal = round($receiptTotal, 2);

        // ZIMRA expects a single condensed payment covering the receipt total.
        $firstMethod = 'Cash';
        foreach ($payments as $payment) {
            $firstMethod = self::getMoneyTypeCode((string) $payment->method);
            break;
        }
        $receiptPayments = [[
            'moneyTypeCode' => $firstMethod,
            'paymentAmount' => $receiptTotal,
        ]];

        // ── Tax aggregation ──────────────────────────────────────────────────
        $taxGroups = [];
        foreach ($receiptLines as $line) {
            $tId = $line['taxID'];
            $tRate = $line['taxPercent'] ?? 0.0;
            $key = $tRate.'_'.$tId;
            if (! isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'taxID' => $tId,
                    'taxPercent' => $tRate,
                    'salesAmountWithTax' => 0.0,
                ];
            }
            $taxGroups[$key]['salesAmountWithTax'] += $line['receiptLineTotal'];
        }

        $receiptTaxes = [];
        foreach ($taxGroups as $group) {
            $taxObj = [
                'taxID' => $group['taxID'],
                'taxAmount' => (float) round(self::taxCalculator($group['salesAmountWithTax'], $group['taxPercent']), 2),
                'salesAmountWithTax' => (float) round($group['salesAmountWithTax'], 2),
            ];

            $taxCodeValue = $taxCodeMap[$group['taxID']] ?? '';
            if ($taxCodeValue !== '') {
                $taxObj['taxCode'] = $taxCodeValue;
            }

            // Only omit taxPercent for taxID=1 (Exempt); zero-rated must send 0.
            if ($group['taxID'] !== 1) {
                $taxObj['taxPercent'] = (float) round($group['taxPercent'], 2);
            }

            $receiptTaxes[] = $taxObj;
        }

        // Sort BEFORE building the payload so the submitted JSON and the signing
        // string use the same order — mismatched order is a guaranteed RCPT020.
        usort($receiptTaxes, function ($a, $b) use ($taxCodeMap) {
            if ($a['taxID'] !== $b['taxID']) {
                return $a['taxID'] <=> $b['taxID'];
            }

            return strcmp($taxCodeMap[$a['taxID']] ?? '', $taxCodeMap[$b['taxID']] ?? '');
        });

        // ── Buyer data (optional; both name and 10-char TIN required — RCPT043) ─
        $buyerData = null;
        // SmartPOS customers have no TIN field yet; include buyerData only when a
        // TIN is stashed in the business metadata per-customer flow later. Walk-in
        // sales legitimately omit buyerData entirely.

        // ── Assemble ─────────────────────────────────────────────────────────
        // Spec §2.4 requires Africa/Harare timestamps; using the sale's original
        // created_at causes RCPT030 when fiscalising older transactions.
        $receiptDate = now('Africa/Harare')->format('Y-m-d\TH:i:s');
        $currencyCode = $transaction->base_currency ?: 'USD';

        $receipt = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => $currencyCode,
            'receiptCounter' => $receiptCounter,
            'receiptGlobalNo' => $receiptGlobalNo,
            'invoiceNo' => $invoiceNo,
            'receiptDate' => $receiptDate,
            'receiptLinesTaxInclusive' => true,
            'receiptLines' => $receiptLines,
            'receiptTaxes' => $receiptTaxes,
            'receiptPayments' => $receiptPayments,
            'receiptTotal' => (float) round($receiptTotal, 2),
            'receiptPrintForm' => 'Receipt48',
        ];

        if ($buyerData !== null) {
            $receipt['buyerData'] = $buyerData;
        }

        // ── Signature (spec §13.2.1) ─────────────────────────────────────────
        // Signing string: deviceID || RECEIPTTYPE || CURRENCY || globalNo || date
        //   || total(cents) || per-tax[taxCode || taxPercent(2dp) || taxAmount(cents)
        //   || salesAmountWithTax(cents)] || previousReceiptHash (when present).
        // taxPercent always uses exactly 2 decimals; exempt lines contribute ''.
        $concatenatedTaxes = '';
        foreach ($receiptTaxes as $tax) {
            $taxCode = $taxCodeMap[$tax['taxID']] ?? '';
            $tpc = isset($tax['taxPercent']) ? number_format(round((float) $tax['taxPercent'], 2), 2, '.', '') : '';
            $tAmt = (int) round($tax['taxAmount'] * 100);
            $sAmt = (int) round($tax['salesAmountWithTax'] * 100);
            $concatenatedTaxes .= $taxCode.$tpc.$tAmt.$sAmt;
        }

        $stringToSign = $device->device_id
            .strtoupper('FiscalInvoice')
            .strtoupper($currencyCode)
            .$receiptGlobalNo
            .$receiptDate
            .((int) round($receiptTotal * 100))
            .$concatenatedTaxes;

        if (! empty($previousHash)) {
            $stringToSign .= $previousHash;
        }

        Log::debug('ZIMRA signing string', [
            'device_id' => $device->device_id,
            'string_to_sign' => $stringToSign,
            'has_previous_hash' => ! empty($previousHash),
        ]);

        $hashValue = hash('sha256', $stringToSign, true);
        $signatureEncoded = '';

        if ($device->private_key_data) {
            $privateKey = openssl_pkey_get_private($device->private_key_data);
            if ($privateKey) {
                openssl_sign($stringToSign, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256);
                $signatureEncoded = base64_encode($signatureBytes);
            }
        }

        $receipt['receiptDeviceSignature'] = [
            'hash' => base64_encode($hashValue),
            'signature' => $signatureEncoded,
        ];

        return ['receipt' => $receipt];
    }

    /**
     * Resolve the ZIMRA taxID + taxPercent for an item's tax rate using the
     * device's applicableTaxes from GetConfig (authoritative). Falls back to
     * legacy hardcoded IDs only when config is unavailable. Prevents RCPT025.
     *
     * @return array{taxID: int, taxPercent: float|null}
     */
    public static function resolveTax(float $taxRate, ?array $applicableTaxes): array
    {
        $rate = round($taxRate, 2);

        if (! empty($applicableTaxes)) {
            foreach ($applicableTaxes as $tax) {
                if ($tax['taxPercent'] !== null && round((float) $tax['taxPercent'], 2) === $rate) {
                    return ['taxID' => (int) $tax['taxID'], 'taxPercent' => (float) $tax['taxPercent']];
                }
            }

            // Rate 0 — prefer zero-rated (taxPercent=0), fall back to exempt (null).
            if ($rate === 0.0) {
                foreach ($applicableTaxes as $tax) {
                    if (isset($tax['taxPercent']) && (float) $tax['taxPercent'] === 0.0) {
                        return ['taxID' => (int) $tax['taxID'], 'taxPercent' => 0.0];
                    }
                }
                foreach ($applicableTaxes as $tax) {
                    if ($tax['taxPercent'] === null) {
                        return ['taxID' => (int) $tax['taxID'], 'taxPercent' => null];
                    }
                }
            }

            // No exact match — use the highest non-zero applicable tax.
            $fallback = null;
            foreach ($applicableTaxes as $tax) {
                if ($tax['taxPercent'] !== null && (float) $tax['taxPercent'] > 0) {
                    if ($fallback === null || (float) $tax['taxPercent'] > (float) $fallback['taxPercent']) {
                        $fallback = $tax;
                    }
                }
            }
            if ($fallback !== null) {
                return ['taxID' => (int) $fallback['taxID'], 'taxPercent' => (float) $fallback['taxPercent']];
            }
        }

        // Legacy fallback when no device config is available.
        return match (true) {
            $rate === 0.0 => ['taxID' => 2, 'taxPercent' => 0.0],
            $rate === 5.0 => ['taxID' => 514, 'taxPercent' => 5.0],
            $rate === 15.0 => ['taxID' => 3, 'taxPercent' => 15.0],
            default => ['taxID' => 515, 'taxPercent' => 15.5],
        };
    }

    private static function getMoneyTypeCode(string $paymentMethod): string
    {
        return match (strtolower($paymentMethod)) {
            'cash' => 'Cash',
            'card', 'credit card', 'debit card' => 'Card',
            'ecocash', 'mobile_money', 'mobile money', 'mobile', 'mobile wallet' => 'MobileWallet',
            'bank_transfer', 'bank transfer', 'bank' => 'BankTransfer',
            'coupon', 'voucher' => 'Coupon',
            'credit', 'layby' => 'Credit',
            default => 'Other',
        };
    }
}
