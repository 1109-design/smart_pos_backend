<?php

namespace App\Services\Zimra;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ZimraSalesService
{
    public function __construct(
        private ZimraClient $client,
        private FiscalisationGuard $fiscalisationGuard,
    ) {}

    /**
     * Entry point at sale time: records a pending ZimraSale and queues the
     * actual submission for sequential per-device processing. Never blocks the
     * sale itself.
     */
    public function queueFiscalisation(Transaction $transaction): array
    {
        return $this->processFiscalisation($transaction, allowSubmit: false);
    }

    /**
     * Entry point from the queue worker: performs the real ZIMRA submission.
     */
    public function processQueued(Transaction $transaction): array
    {
        return $this->processFiscalisation($transaction, allowSubmit: true);
    }

    /**
     * Re-submit every pending/retry sale, oldest first, per device.
     */
    public function retryFailedFiscalisations(): array
    {
        $results = ['attempted' => 0, 'fiscalised' => 0, 'failed' => 0];

        $pending = ZimraSale::whereIn('status', ['pending', 'retry'])
            ->orderBy('created_at')
            ->get();

        foreach ($pending as $zimraSale) {
            $transaction = $zimraSale->transaction;
            if (! $transaction) {
                continue;
            }

            $results['attempted']++;
            $result = $this->processQueued($transaction);
            if ($result['fiscalised'] ?? false) {
                $results['fiscalised']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    private function processFiscalisation(Transaction $transaction, bool $allowSubmit): array
    {
        $docNumber = $transaction->sale_number ?: (string) $transaction->id;

        if ($transaction->fiscal_status === 'fiscalised') {
            return ['success' => true, 'message' => 'Already fiscalised', 'fiscalised' => true];
        }

        // Per-business master switch: fiscalisation is opt-in.
        $business = Business::find($transaction->business_id);
        if (! $business || ! $business->fiscalisation_enabled) {
            return [
                'success' => true,
                'message' => 'Fiscalisation is disabled for this business',
                'fiscalised' => false,
                'skipped' => true,
            ];
        }

        // Environment/host guard: refuse to touch live ZIMRA devices from a
        // non-production runtime (e.g. a cloned production database on a dev
        // machine). Skip cleanly so the sale is unaffected.
        if ($guardReason = $this->fiscalisationGuard->blockReason()) {
            Log::warning('ZIMRA fiscalisation skipped — environment guard', [
                'transaction_id' => $transaction->id,
                'reason' => $guardReason,
            ]);

            return [
                'success' => true,
                'message' => $guardReason,
                'fiscalised' => false,
                'skipped' => true,
                'guard_blocked' => true,
            ];
        }

        $device = ZimraDevice::where('business_id', $transaction->business_id)
            ->where('is_active', true)
            ->first();

        if (! $device) {
            $transaction->update(['fiscal_status' => 'not_configured']);

            return [
                'success' => false,
                'message' => 'No active ZIMRA device configured for this business',
                'fiscalised' => false,
            ];
        }

        try {
            $zimraSale = ZimraSale::firstOrNew(['transaction_id' => $transaction->id]);

            // A receipt ZIMRA has already accepted must never be resubmitted —
            // that would duplicate it in the fiscal chain (RCPT013 at best).
            if ($zimraSale->exists && $zimraSale->hasZimraReceiptEvidence()) {
                $transaction->update([
                    'fiscal_status' => 'fiscalised',
                    'fiscal_receipt_number' => $zimraSale->zimra_receipt_id,
                    'fiscal_qr_code' => $zimraSale->zimra_qr_code,
                ]);

                return ['success' => true, 'message' => 'Already accepted by ZIMRA', 'fiscalised' => true];
            }

            $zimraSale->fill([
                'business_id' => $transaction->business_id,
                'tin' => $device->tin,
                'device_id' => $device->device_id,
                'status' => 'pending',
                'error_message' => null,
            ])->save();
            $zimraSale->refresh();

            // Spec §6.2: invoiceNo must be unique per submission attempt.
            $retryIndex = $zimraSale->retry_count;
            $submissionInvoiceNo = $retryIndex > 0 ? $docNumber.'-R'.$retryIndex : $docNumber;

            if (! $allowSubmit) {
                // Queueing is not a retry — leave retry_count untouched so the
                // first real submission uses the plain invoice number (spec §6.2
                // -R{n} suffixes are for genuine resubmissions only).
                $zimraSale->appendRetryHistory([
                    'invoice_no_used' => $submissionInvoiceNo,
                    'result' => 'queued',
                    'device_id' => $device->device_id,
                ]);
                $transaction->update(['fiscal_status' => 'pending']);

                ProcessZimraFiscalisationJob::dispatch((string) $device->device_id);

                return [
                    'success' => true,
                    'message' => 'Fiscalisation queued',
                    'fiscalised' => false,
                    'queued' => true,
                ];
            }

            // Ensure tax codes are loaded from GetConfig (refresh when all codes
            // are empty — ZIMRA may have populated them since the first fetch).
            $taxCodeMap = $device->tax_codes ?? [];
            $allEmpty = ! empty($taxCodeMap) && count(array_filter($taxCodeMap, fn ($v) => $v !== '')) === 0;
            if (empty($taxCodeMap) || $allEmpty) {
                $configResponse = $this->client->getDeviceConfig($device->device_id, $device);
                if ($configResponse['success']) {
                    $device->refresh();
                    $taxCodeMap = $device->tax_codes ?? [];
                }
            }

            return $this->submitUnderDeviceLock($transaction, $zimraSale, $device, $taxCodeMap, $submissionInvoiceNo);
        } catch (Exception $e) {
            Log::error('ZIMRA fiscalisation exception', [
                'transaction_id' => $transaction->id,
                'exception' => $e->getMessage(),
            ]);

            if (isset($zimraSale) && $zimraSale->exists) {
                $zimraSale->markAsRetry();
            }
            $transaction->update(['fiscal_status' => 'pending']);

            return ['success' => false, 'message' => $e->getMessage(), 'fiscalised' => false];
        }
    }

    /**
     * GetStatus → SubmitReceipt → persist chain data as one atomic unit per
     * device. Without the lock, two concurrent submissions read the same
     * lastReceiptGlobalNo and collide (RCPT012); releasing the lock before the
     * signature hash is persisted breaks the previousReceiptHash chain (RCPT020).
     */
    private function submitUnderDeviceLock(Transaction $transaction, ZimraSale $zimraSale, ZimraDevice $device, array $taxCodeMap, string $invoiceNo): array
    {
        $lockName = 'zimra_device_'.$device->device_id;
        $usingMysqlLock = DB::connection()->getDriverName() === 'mysql';

        if ($usingMysqlLock) {
            $acquired = DB::selectOne('SELECT GET_LOCK(?, 60) as acquired', [$lockName]);
            if (! $acquired || ! $acquired->acquired) {
                throw new Exception("Could not acquire fiscalisation lock for device {$device->device_id} after 60 seconds.");
            }
        }

        try {
            $statusResponse = $this->client->getStatus($device->device_id, $device);

            if (! $statusResponse['success']) {
                $zimraSale->markAsRetry();
                $zimraSale->appendRetryHistory([
                    'invoice_no_used' => $invoiceNo,
                    'result' => 'status_check_failed',
                    'error' => $statusResponse['error'] ?? 'unknown',
                ]);
                $transaction->update(['fiscal_status' => 'pending']);

                return [
                    'success' => true,
                    'message' => 'Fiscalisation pending: GetStatus failed — '.($statusResponse['error'] ?? 'unknown'),
                    'fiscalised' => false,
                ];
            }

            $statusData = $statusResponse['data'];

            // Auto-open the fiscal day when closed (a POS runs continuously;
            // requiring a manual open before the first morning sale is a trap).
            if (($statusData['fiscalDayStatus'] ?? null) === 'FiscalDayClosed') {
                $openResult = $this->client->openFiscalDay($device->device_id, $device);
                if (! $openResult['success']) {
                    $zimraSale->markAsRetry();
                    $transaction->update(['fiscal_status' => 'pending']);

                    return [
                        'success' => true,
                        'message' => 'Fiscalisation pending: could not open fiscal day — '.($openResult['error'] ?? 'unknown'),
                        'fiscalised' => false,
                    ];
                }

                $statusResponse = $this->client->getStatus($device->device_id, $device);
                if (! $statusResponse['success']) {
                    $zimraSale->markAsRetry();
                    $transaction->update(['fiscal_status' => 'pending']);

                    return ['success' => true, 'message' => 'Fiscalisation pending: status re-check failed after day open', 'fiscalised' => false];
                }
                $statusData = $statusResponse['data'];
            }

            $receiptGlobalNo = (int) ($statusData['lastReceiptGlobalNo'] ?? 0) + 1;
            $receiptCounter = (int) ($statusData['lastReceiptCounter'] ?? 0) + 1;

            // Hash chains are per fiscal day (spec §2.3): the previous hash is the
            // device signature hash of the last accepted receipt in THIS day only.
            $previousHash = null;
            if ($receiptCounter > 1 && $device->fiscal_day_opened_at) {
                $previousHash = ZimraSale::where('device_id', $device->device_id)
                    ->where('status', 'fiscalised')
                    ->where('fiscalised_at', '>=', $device->fiscal_day_opened_at)
                    ->orderByDesc('receipt_global_no')
                    ->value('device_signature_hash');
            }

            $zimraSale->update(['receipt_global_no' => $receiptGlobalNo, 'fiscal_invoice_no' => $invoiceNo]);

            $items = TransactionItem::where('transaction_id', $transaction->id)->get();
            $payments = Payment::where('transaction_id', $transaction->id)->get();
            $business = Business::find($transaction->business_id);
            $customer = $transaction->customer_id ? Customer::find($transaction->customer_id) : null;

            $receiptData = ZimraReceiptFormatter::formatReceipt(
                $transaction,
                $items,
                $payments,
                $business,
                $customer,
                $device,
                $previousHash,
                $receiptGlobalNo,
                $receiptCounter,
                $taxCodeMap,
                $device->applicable_taxes ?? [],
                $invoiceNo
            );

            $apiResponse = $this->client->submitReceipt($device->device_id, $receiptData, $device);

            if ($apiResponse['success']) {
                $zimraData = $apiResponse['data'];
                $zimraReference = is_array($zimraData['receiptServerSignature'] ?? null)
                    ? ($zimraData['receiptServerSignature']['signature'] ?? null)
                    : ($zimraData['receiptServerSignature'] ?? null);
                $zimraReference = $zimraReference ?: ($zimraData['receiptID'] ?? null);

                if ($zimraReference) {
                    // Persist chain data before releasing the lock so the next
                    // receipt finds this hash as its previousReceiptHash.
                    $zimraSale->markAsFiscalised((string) $zimraReference, $zimraData, $receiptData);
                    $zimraSale->appendRetryHistory([
                        'invoice_no_used' => $invoiceNo,
                        'receipt_global_no' => $receiptGlobalNo,
                        'result' => 'fiscalised',
                        'zimra_receipt_id' => $zimraData['receiptID'] ?? null,
                    ]);
                }
            }
        } finally {
            if ($usingMysqlLock) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }

        if (! $apiResponse['success']) {
            // A network failure differs from a ZIMRA rejection: ZIMRA may have
            // accepted the receipt before the connection dropped. Confirm via
            // GetStatus before treating this as a failure.
            if ($this->recoverAfterNetworkFailure($device, $zimraSale, $receiptGlobalNo, $receiptData)) {
                $transaction->update([
                    'fiscal_status' => 'fiscalised',
                    'fiscal_receipt_number' => 'timeout-recovered-'.$receiptGlobalNo,
                    'fiscal_qr_code' => $zimraSale->zimra_qr_code,
                ]);
                $this->emitFiscalSyncRecord($transaction);

                return ['success' => true, 'message' => 'Fiscalised (recovered after network timeout)', 'fiscalised' => true];
            }

            $zimraSale->markAsRetry();
            $zimraSale->appendRetryHistory([
                'invoice_no_used' => $invoiceNo,
                'receipt_global_no' => $receiptGlobalNo,
                'result' => 'api_error',
                'error' => $apiResponse['error'] ?? 'ZIMRA unavailable',
            ]);
            $transaction->update(['fiscal_status' => 'pending']);

            return [
                'success' => true,
                'message' => 'Fiscalisation pending: '.($apiResponse['error'] ?? 'ZIMRA unavailable'),
                'fiscalised' => false,
            ];
        }

        $zimraData = $apiResponse['data'];

        // ZIMRA assigns a receiptID even to invalid receipts; the chain row is
        // tracked above, but the sale must not display as fiscalised while red
        // validation errors are outstanding.
        $redErrors = array_filter(
            $zimraData['validationErrors'] ?? [],
            fn ($e) => strtolower($e['validationErrorColor'] ?? '') === 'red'
        );

        if (! empty($redErrors)) {
            $codes = implode(', ', array_column($redErrors, 'validationErrorCode'));
            $zimraSale->update(['error_message' => 'ZIMRA red validation errors: '.$codes]);
            $transaction->update(['fiscal_status' => 'failed']);

            Log::error('ZIMRA red validation errors', [
                'transaction_id' => $transaction->id,
                'codes' => $codes,
            ]);

            return [
                'success' => true,
                'message' => 'Receipt accepted into chain with red errors: '.$codes,
                'fiscalised' => false,
                'red_errors' => array_values($redErrors),
            ];
        }

        $transaction->update([
            'fiscal_status' => 'fiscalised',
            'fiscal_receipt_number' => $zimraData['receiptID'] ?? null,
            'fiscal_qr_code' => $zimraSale->zimra_qr_code,
        ]);
        $this->emitFiscalSyncRecord($transaction);

        return [
            'success' => true,
            'message' => 'Fiscalised successfully',
            'fiscalised' => true,
            'zimra_receipt_id' => $zimraData['receiptID'] ?? null,
        ];
    }

    /**
     * Publish the fiscalised transaction to the sync stream (device_id = null →
     * every device pulls it) so POS devices learn the QR code and fiscal receipt
     * number for re-prints and transaction history.
     */
    private function emitFiscalSyncRecord(Transaction $transaction): void
    {
        $transaction->refresh();

        SyncRecord::create([
            'business_id' => $transaction->business_id,
            'table_name' => 'transactions',
            'record_uuid' => $transaction->id,
            'operation' => 'upsert',
            // Built as plain literals, not $transaction->only(): Transaction
            // casts subtotal/tax_total/discount_total/total as decimal:N,
            // which Eloquent renders as a STRING to avoid float precision
            // loss. Left unchanged, that string lands in the sync payload's
            // JSON and breaks the device's `as num?` cast on pull.
            'payload' => [
                'business_id' => $transaction->business_id,
                'location_id' => $transaction->location_id,
                'user_id' => $transaction->user_id,
                'customer_id' => $transaction->customer_id,
                'subtotal' => (float) $transaction->subtotal,
                'tax_total' => (float) $transaction->tax_total,
                'discount_total' => (float) $transaction->discount_total,
                'deposit_total' => (float) $transaction->deposit_total,
                'surcharge_total' => (float) $transaction->surcharge_total,
                'total' => (float) $transaction->total,
                'base_currency' => $transaction->base_currency,
                'status' => $transaction->status,
                'sale_number' => $transaction->sale_number,
                'notes' => $transaction->notes,
                'fiscal_status' => $transaction->fiscal_status,
                'fiscal_receipt_number' => $transaction->fiscal_receipt_number,
                'fiscal_qr_code' => $transaction->fiscal_qr_code,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    /**
     * After a network-level submit failure, ask GetStatus whether ZIMRA in fact
     * accepted the receipt (lastReceiptGlobalNo advanced to ours). If so, mark
     * it fiscalised locally instead of resubmitting a duplicate.
     */
    private function recoverAfterNetworkFailure(ZimraDevice $device, ZimraSale $zimraSale, int $receiptGlobalNo, array $receiptData): bool
    {
        $statusResponse = $this->client->getStatus($device->device_id, $device);

        if (! $statusResponse['success']) {
            return false;
        }

        $lastGlobalNo = (int) ($statusResponse['data']['lastReceiptGlobalNo'] ?? 0);

        if ($lastGlobalNo >= $receiptGlobalNo) {
            Log::warning('ZIMRA receipt recovered after network failure', [
                'device_id' => $device->device_id,
                'receipt_global_no' => $receiptGlobalNo,
                'zimra_last_global_no' => $lastGlobalNo,
            ]);

            $zimraSale->markAsFiscalised('timeout-recovered', ['receiptID' => null], $receiptData);

            return true;
        }

        return false;
    }
}
