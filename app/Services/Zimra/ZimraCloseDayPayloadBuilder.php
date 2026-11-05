<?php

namespace App\Services\Zimra;

use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use Illuminate\Support\Facades\Log;

/**
 * Builds the ZIMRA CloseDay payload by summing the EXACT receipts submitted
 * during the fiscal day (each ZimraSale stores its submitted_receipt snapshot).
 * Summing snapshots — never recomputing from source documents — guarantees the
 * close-day counters match what ZIMRA accumulated, preventing CountersMismatch.
 */
class ZimraCloseDayPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ZimraDevice $device, int $fiscalDayNo, ?int $lastReceiptCounter): array
    {
        $openedAt = $device->fiscal_day_opened_at ?? now()->startOfDay();

        $acceptedRows = ZimraSale::query()
            ->where('device_id', (string) $device->device_id)
            ->whereIn('status', ZimraSale::IN_CHAIN_STATUSES)
            ->whereNotNull('fiscalised_at')
            ->where('fiscalised_at', '>=', $openedAt)
            ->orderBy('receipt_global_no')
            ->get();

        $saleByTax = [];
        $balanceByType = [];

        foreach ($acceptedRows as $zimraSale) {
            $snapshot = $zimraSale->submitted_receipt;
            if (! is_array($snapshot) || empty($snapshot['receiptTaxes'])) {
                continue;
            }

            $currency = strtoupper((string) ($snapshot['receiptCurrency'] ?? 'USD'));
            $counterPrefix = ($snapshot['receiptType'] ?? 'FiscalInvoice') === 'CreditNote' ? 'CreditNote' : 'Sale';

            foreach ($snapshot['receiptTaxes'] as $tax) {
                $taxPercent = array_key_exists('taxPercent', $tax) ? (float) $tax['taxPercent'] : null;
                $key = $counterPrefix.'_'.($taxPercent === null ? 'null' : (string) round($taxPercent, 2)).'_'.$currency;

                $saleByTax[$key] ??= [
                    'counterPrefix' => $counterPrefix,
                    'taxPercent' => $taxPercent,
                    'currency' => $currency,
                    'value' => 0.0,
                    'taxAmount' => 0.0,
                    'taxID' => isset($tax['taxID']) ? (int) $tax['taxID'] : null,
                ];
                $saleByTax[$key]['value'] += (float) ($tax['salesAmountWithTax'] ?? 0);
                $saleByTax[$key]['taxAmount'] += (float) ($tax['taxAmount'] ?? 0);
            }

            foreach ($snapshot['receiptPayments'] ?? [] as $payment) {
                $moneyType = (string) ($payment['moneyTypeCode'] ?? 'Other');
                $key = $moneyType.'_'.$currency;

                $balanceByType[$key] ??= ['moneyType' => $moneyType, 'currency' => $currency, 'value' => 0.0];
                $balanceByType[$key]['value'] += (float) ($payment['paymentAmount'] ?? 0);
            }
        }

        $counters = $this->buildCounters($saleByTax, $balanceByType);
        $receiptCounter = $lastReceiptCounter ?? $acceptedRows->count();
        // The fiscal day's date is the Harare date it opened on: a day opened just
        // after midnight there is still the previous date in UTC.
        $fiscalDayDate = $openedAt->copy()->timezone('Africa/Harare')->toDateString();
        $signingString = $this->signingString((string) $device->device_id, $fiscalDayNo, $fiscalDayDate, $counters);

        Log::info('ZIMRA CloseDay payload built', [
            'device_id' => (string) $device->device_id,
            'fiscal_day_no' => $fiscalDayNo,
            'accepted_rows' => $acceptedRows->count(),
            'counter_count' => count($counters),
        ]);

        return [
            'deviceID' => (int) $device->device_id,
            'fiscalDayNo' => $fiscalDayNo,
            'fiscalDayCounters' => $counters,
            'fiscalDayDeviceSignature' => [
                'hash' => base64_encode(hash('sha256', $signingString, true)),
                'signature' => $this->sign($device, $signingString),
            ],
            'receiptCounter' => $receiptCounter,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $saleByTax
     * @param  array<string, array<string, mixed>>  $balanceByType
     * @return array<int, array<string, mixed>>
     */
    private function buildCounters(array $saleByTax, array $balanceByType): array
    {
        $counters = [];

        foreach ($saleByTax as $entry) {
            $taxPercent = $entry['taxPercent'] === null ? null : (float) $entry['taxPercent'];
            $taxId = $entry['taxID'] ?? ($taxPercent === null ? 3 : 2);

            // Credit note counters run negative in the FDMS spec; only zeros are left out.
            if (round((float) $entry['value'], 2) != 0) {
                $counter = [
                    'fiscalCounterType' => $entry['counterPrefix'].'ByTax',
                    'fiscalCounterCurrency' => $entry['currency'],
                    'fiscalCounterTaxID' => $taxId,
                    'fiscalCounterValue' => round((float) $entry['value'], 2),
                ];
                if ($taxPercent !== null) {
                    $counter['fiscalCounterTaxPercent'] = $taxPercent;
                }
                $counters[] = $counter;
            }

            if (round((float) $entry['taxAmount'], 2) != 0) {
                $counters[] = [
                    'fiscalCounterType' => $entry['counterPrefix'].'TaxByTax',
                    'fiscalCounterCurrency' => $entry['currency'],
                    'fiscalCounterTaxID' => $taxId,
                    'fiscalCounterTaxPercent' => $taxPercent,
                    'fiscalCounterValue' => round((float) $entry['taxAmount'], 2),
                ];
            }
        }

        foreach ($balanceByType as $entry) {
            if (round((float) $entry['value'], 2) == 0) {
                continue;
            }

            $counters[] = [
                'fiscalCounterType' => 'BalanceByMoneyType',
                'fiscalCounterCurrency' => $entry['currency'],
                'fiscalCounterMoneyType' => $entry['moneyType'],
                'fiscalCounterValue' => round((float) $entry['value'], 2),
            ];
        }

        usort($counters, fn (array $a, array $b): int => $this->counterSortKey($a) <=> $this->counterSortKey($b));

        return $counters;
    }

    /**
     * @param  array<string, mixed>  $counter
     * @return array<int, string|int>
     */
    private function counterSortKey(array $counter): array
    {
        $typeOrder = [
            'SaleByTax' => 0,
            'SaleTaxByTax' => 1,
            'CreditNoteByTax' => 2,
            'CreditNoteTaxByTax' => 3,
            'BalanceByMoneyType' => 6,
        ];

        return [
            $typeOrder[$counter['fiscalCounterType']] ?? 99,
            $counter['fiscalCounterCurrency'] ?? '',
            $counter['fiscalCounterTaxID'] ?? PHP_INT_MAX,
            $counter['fiscalCounterMoneyType'] ?? '',
        ];
    }

    /**
     * Canonical CloseDay signing string (spec §13.2.2):
     * deviceID || fiscalDayNo || fiscalDayDate || per-counter[TYPE || CURRENCY
     * || (moneyType | taxPercent 2dp) || value(cents)].
     *
     * @param  array<int, array<string, mixed>>  $counters
     */
    private function signingString(string $deviceId, int $fiscalDayNo, string $fiscalDayDate, array $counters): string
    {
        $counterString = '';

        foreach ($counters as $counter) {
            $type = strtoupper((string) $counter['fiscalCounterType']);
            $currency = strtoupper((string) $counter['fiscalCounterCurrency']);
            $valueCents = (int) round(((float) $counter['fiscalCounterValue']) * 100);

            if ($counter['fiscalCounterType'] === 'BalanceByMoneyType') {
                $counterString .= $type.$currency.strtoupper((string) $counter['fiscalCounterMoneyType']).$valueCents;

                continue;
            }

            $taxPercent = isset($counter['fiscalCounterTaxPercent'])
                ? number_format(round((float) $counter['fiscalCounterTaxPercent'], 2), 2, '.', '')
                : '';
            $counterString .= $type.$currency.$taxPercent.$valueCents;
        }

        return $deviceId.$fiscalDayNo.$fiscalDayDate.$counterString;
    }

    private function sign(ZimraDevice $device, string $signingString): string
    {
        $privateKey = openssl_pkey_get_private((string) $device->private_key_data);

        if (! $privateKey) {
            return '';
        }

        openssl_sign($signingString, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
