<?php

namespace App\Services\Zimra;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Zimra\FiscalDocument;
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
     * Submits one queued row — a till sale's or a fiscal document's. Null
     * when what it was for is gone.
     *
     * @return array<string, mixed>|null
     */
    public function processPending(ZimraSale $zimraSale): ?array
    {
        if ($zimraSale->fiscal_document_id) {
            $document = FiscalDocument::find($zimraSale->fiscal_document_id);

            return $document ? $this->processQueuedDocument($document) : null;
        }
        $transaction = $zimraSale->transaction;

        return $transaction ? $this->processQueued($transaction) : null;
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
            $result = $this->processPending($zimraSale);
            if ($result === null) {
                continue;
            }

            $results['attempted']++;
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

        // Per-sale cashier override: the checkout "Fiscalise this sale"
        // toggle was unticked. This is the actual compliance-enforcing
        // check — the client already strips this sale's tax fields, but
        // without this guard the flag would be cosmetic and the sale would
        // still get submitted to ZIMRA.
        if (! $transaction->fiscalisation_requested) {
            if ($transaction->fiscal_status !== 'excluded') {
                $transaction->update(['fiscal_status' => 'excluded']);
            }

            return [
                'success' => true,
                'message' => 'Fiscalisation excluded for this sale by the cashier',
                'fiscalised' => false,
                'skipped' => true,
            ];
        }

        // Voided before it was ever submitted: nothing to fiscalise (a void
        // after fiscalisation is a credit note — Phase 11.2).
        if ($transaction->status === 'voided') {
            $transaction->update(['fiscal_status' => 'excluded']);

            return ['success' => true, 'message' => 'Voided before fiscalisation', 'fiscalised' => false, 'skipped' => true];
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

        // A chain registers one fiscal device per branch (ZIMRA requires its
        // own fiscal day/receipt sequence per till/branch). Prefer the device
        // registered for this sale's location; fall back to a business-wide
        // device (location_id null) for businesses that haven't split by
        // branch yet — keeps single-location businesses working unchanged.
        $device = ZimraDevice::where('business_id', $transaction->business_id)
            ->where('is_active', true)
            ->where(function ($query) use ($transaction) {
                if ($transaction->location_id) {
                    $query->where('location_id', $transaction->location_id)
                        ->orWhereNull('location_id');
                } else {
                    $query->whereNull('location_id');
                }
            })
            ->orderByRaw('location_id IS NULL')
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

            return $this->submitUnderDeviceLock($this->transactionSubject($transaction), $zimraSale, $device, $taxCodeMap, $submissionInvoiceNo);
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
    private function submitUnderDeviceLock(FiscalSubject $subject, ZimraSale $zimraSale, ZimraDevice $device, array $taxCodeMap, string $invoiceNo): array
    {
        $setStatus = $subject->setStatus;
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
                $setStatus('pending');

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
                    $setStatus('pending');

                    return [
                        'success' => true,
                        'message' => 'Fiscalisation pending: could not open fiscal day — '.($openResult['error'] ?? 'unknown'),
                        'fiscalised' => false,
                    ];
                }

                $statusResponse = $this->client->getStatus($device->device_id, $device);
                if (! $statusResponse['success']) {
                    $zimraSale->markAsRetry();
                    $setStatus('pending');

                    return ['success' => true, 'message' => 'Fiscalisation pending: status re-check failed after day open', 'fiscalised' => false];
                }
                $statusData = $statusResponse['data'];
            }

            $receiptGlobalNo = (int) ($statusData['lastReceiptGlobalNo'] ?? 0) + 1;

            // ZIMRA GetStatus does not consistently return lastReceiptCounter (often
            // only returning lastReceiptGlobalNo). When absent, compute it from the
            // receipts recorded for this device in the current fiscal day.
            $dayOpenedAt = $device->fiscal_day_opened_at ?? now()->startOfDay();
            if (isset($statusData['lastReceiptCounter']) && (int) $statusData['lastReceiptCounter'] > 0) {
                $receiptCounter = (int) $statusData['lastReceiptCounter'] + 1;
            } else {
                $dayReceiptsCount = ZimraSale::where('device_id', $device->device_id)
                    ->whereNotNull('fiscalised_at')
                    ->where('fiscalised_at', '>=', $dayOpenedAt)
                    ->count();
                $receiptCounter = $dayReceiptsCount + 1;
            }

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

            $business = Business::find($subject->shape->business_id);

            $receiptData = ZimraReceiptFormatter::formatReceipt(
                $subject->shape,
                $subject->items,
                $subject->payments,
                $business,
                $subject->customer,
                $device,
                $previousHash,
                $receiptGlobalNo,
                $receiptCounter,
                $taxCodeMap,
                $device->applicable_taxes ?? [],
                $invoiceNo,
                $subject->formatterOptions()
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
                $setStatus('fiscalised', 'timeout-recovered-'.$receiptGlobalNo, $zimraSale->zimra_qr_code);

                return ['success' => true, 'message' => 'Fiscalised (recovered after network timeout)', 'fiscalised' => true];
            }

            $zimraSale->markAsRetry();
            $zimraSale->appendRetryHistory([
                'invoice_no_used' => $invoiceNo,
                'receipt_global_no' => $receiptGlobalNo,
                'result' => 'api_error',
                'error' => $apiResponse['error'] ?? 'ZIMRA unavailable',
            ]);
            $setStatus('pending');

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
            $setStatus('failed', null, null, 'ZIMRA red validation errors: '.$codes);

            Log::error('ZIMRA red validation errors', [
                'subject' => $subject->label,
                'codes' => $codes,
            ]);

            return [
                'success' => true,
                'message' => 'Receipt accepted into chain with red errors: '.$codes,
                'fiscalised' => false,
                'red_errors' => array_values($redErrors),
            ];
        }

        $setStatus('fiscalised', isset($zimraData['receiptID']) ? (string) $zimraData['receiptID'] : null, $zimraSale->zimra_qr_code);

        return [
            'success' => true,
            'message' => 'Fiscalised successfully',
            'fiscalised' => true,
            'zimra_receipt_id' => $zimraData['receiptID'] ?? null,
        ];
    }

    /**
     * A till sale as a submission: its items and payments, and where its
     * outcome is recorded (the transaction's fiscal fields, then pushed to
     * every device).
     */
    private function transactionSubject(Transaction $transaction): FiscalSubject
    {
        return new FiscalSubject(
            label: 'transaction '.$transaction->id,
            shape: $transaction,
            items: TransactionItem::where('transaction_id', $transaction->id)->get(),
            payments: Payment::where('transaction_id', $transaction->id)->get(),
            customer: $transaction->customer_id ? Customer::find($transaction->customer_id) : null,
            receiptType: 'FiscalInvoice',
            currency: null,
            creditDebitNote: null,
            receiptNotes: null,
            setStatus: function (string $status, ?string $receiptId = null, ?string $qr = null, ?string $error = null) use ($transaction): void {
                if ($status !== 'fiscalised') {
                    $transaction->update(['fiscal_status' => $status]);

                    return;
                }
                $transaction->update([
                    'fiscal_status' => 'fiscalised',
                    'fiscal_receipt_number' => $receiptId,
                    'fiscal_qr_code' => $qr,
                ]);
                $this->emitFiscalSyncRecord($transaction);
            },
        );
    }

    // ── Fiscal documents (Phase 11.2) ─────────────────────────────────────

    /**
     * Records a pending submission for a fiscal document and queues it on
     * its device, like a till sale.
     */
    public function queueDocument(FiscalDocument $document): array
    {
        return $this->processDocument($document, allowSubmit: false);
    }

    public function processQueuedDocument(FiscalDocument $document): array
    {
        return $this->processDocument($document, allowSubmit: true);
    }

    private function processDocument(FiscalDocument $document, bool $allowSubmit): array
    {
        if ($document->status === 'fiscalised') {
            return ['success' => true, 'message' => 'Already fiscalised', 'fiscalised' => true];
        }
        $finish = function (string $status, ?string $error = null) use ($document): array {
            $document->update(['status' => $status, 'error_message' => $error]);
            $this->emitDocumentSyncRecord($document);

            return ['success' => true, 'message' => $error ?? $status, 'fiscalised' => false, 'skipped' => true];
        };

        $business = Business::find($document->business_id);
        if (! $business || ! $business->fiscalisation_enabled) {
            return $finish('not_required', 'Fiscalisation is disabled for this business');
        }
        if ($guardReason = $this->fiscalisationGuard->blockReason()) {
            return ['success' => true, 'message' => $guardReason, 'fiscalised' => false, 'skipped' => true, 'guard_blocked' => true];
        }

        $device = ZimraDevice::where('business_id', $document->business_id)
            ->where('is_active', true)
            ->where(function ($query) use ($document) {
                if ($document->location_id) {
                    $query->where('location_id', $document->location_id)->orWhereNull('location_id');
                } else {
                    $query->whereNull('location_id');
                }
            })
            ->orderByRaw('location_id IS NULL')
            ->first();
        if (! $device) {
            $document->update(['status' => 'failed', 'error_message' => 'No active ZIMRA device configured for this business']);

            return ['success' => false, 'message' => 'No active ZIMRA device configured for this business', 'fiscalised' => false];
        }

        // A note credits or debits a receipt ZIMRA already holds (RCPT015 /
        // RCPT032). Until the original is fiscalised the note waits; if the
        // original never will be, neither will the note.
        $creditDebitNote = null;
        if ($document->document_type !== 'FiscalInvoice') {
            $original = $this->originalReceipt($document);
            if ($original === 'never') {
                return $finish('not_required', 'The original was never fiscalised.');
            }
            if ($original === null) {
                // Stays queued on its device; the next run tries again.
                ZimraSale::firstOrNew(['fiscal_document_id' => $document->id])->fill([
                    'business_id' => $document->business_id,
                    'tin' => $device->tin,
                    'device_id' => $device->device_id,
                    'status' => 'pending',
                ])->save();

                return ['success' => true, 'message' => 'Waiting for the original to be fiscalised', 'fiscalised' => false, 'skipped' => true];
            }
            $creditDebitNote = $original;
        }

        try {
            $zimraSale = ZimraSale::firstOrNew(['fiscal_document_id' => $document->id]);
            if ($zimraSale->exists && $zimraSale->hasZimraReceiptEvidence()) {
                $document->update([
                    'status' => 'fiscalised',
                    'fiscal_receipt_id' => (string) (data_get($zimraSale->zimra_response, 'receiptID') ?? $zimraSale->zimra_receipt_id),
                    'fiscal_qr_code' => $zimraSale->zimra_qr_code,
                ]);
                $this->emitDocumentSyncRecord($document);

                return ['success' => true, 'message' => 'Already accepted by ZIMRA', 'fiscalised' => true];
            }
            $zimraSale->fill([
                'business_id' => $document->business_id,
                'tin' => $device->tin,
                'device_id' => $device->device_id,
                'status' => 'pending',
                'error_message' => null,
            ])->save();
            $zimraSale->refresh();

            $retryIndex = $zimraSale->retry_count;
            $invoiceNo = $retryIndex > 0 ? $document->document_number.'-R'.$retryIndex : $document->document_number;

            if (! $allowSubmit) {
                $zimraSale->appendRetryHistory(['invoice_no_used' => $invoiceNo, 'result' => 'queued', 'device_id' => $device->device_id]);
                $document->update(['status' => 'pending']);
                ProcessZimraFiscalisationJob::dispatch((string) $device->device_id);

                return ['success' => true, 'message' => 'Fiscalisation queued', 'fiscalised' => false, 'queued' => true];
            }

            $taxCodeMap = $device->tax_codes ?? [];
            if (empty($taxCodeMap)) {
                $configResponse = $this->client->getDeviceConfig($device->device_id, $device);
                if ($configResponse['success']) {
                    $device->refresh();
                    $taxCodeMap = $device->tax_codes ?? [];
                }
            }

            return $this->submitUnderDeviceLock(
                $this->documentSubject($document, $creditDebitNote),
                $zimraSale,
                $device,
                $taxCodeMap,
                $invoiceNo,
            );
        } catch (Exception $e) {
            Log::error('ZIMRA fiscal document exception', ['fiscal_document_id' => $document->id, 'exception' => $e->getMessage()]);
            if (isset($zimraSale) && $zimraSale->exists) {
                $zimraSale->markAsRetry();
            }
            $document->update(['status' => 'pending', 'error_message' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage(), 'fiscalised' => false];
        }
    }

    /**
     * The creditDebitNote reference for a note, null while the original
     * isn't fiscalised yet, or 'never' when it won't be.
     *
     * @return array<string, int>|string|null
     */
    private function originalReceipt(FiscalDocument $document): array|string|null
    {
        if ($document->original_transaction_id) {
            $sale = ZimraSale::where('transaction_id', $document->original_transaction_id)->first();
            $transaction = Transaction::find($document->original_transaction_id);
            if ($sale === null || ! $sale->hasZimraReceiptEvidence()) {
                $neverWill = $transaction === null
                    || ! $transaction->fiscalisation_requested
                    || $transaction->status === 'voided'
                    || in_array($transaction->fiscal_status, ['excluded', 'not_configured'], true);

                return $neverWill ? 'never' : null;
            }
        } elseif ($document->original_document_id) {
            $originalDoc = FiscalDocument::find($document->original_document_id);
            if ($originalDoc === null || $originalDoc->status === 'not_required') {
                return 'never';
            }
            $sale = ZimraSale::where('fiscal_document_id', $originalDoc->id)->first();
            if ($sale === null || ! $sale->hasZimraReceiptEvidence()) {
                return null;
            }
        } else {
            return 'never';
        }

        $receiptId = data_get($sale->zimra_response, 'receiptID');
        if ($receiptId !== null && is_numeric($receiptId)) {
            return ['receiptID' => (int) $receiptId];
        }

        // No receipt id (recovered after a timeout): device + global number.
        return array_filter([
            'deviceID' => (int) $sale->device_id,
            'receiptGlobalNo' => (int) $sale->receipt_global_no,
            'fiscalDayNo' => data_get($sale->submitted_receipt, 'fiscalDayNo') !== null
                ? (int) data_get($sale->submitted_receipt, 'fiscalDayNo')
                : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * A fiscal document as a submission. Lines and payments come from the
     * device as magnitudes; a credit note's are made negative here (RCPT022
     * / RCPT028 / RCPT040), quantities stay positive.
     *
     * @param  array<string, mixed>|null  $creditDebitNote
     */
    private function documentSubject(FiscalDocument $document, ?array $creditDebitNote): FiscalSubject
    {
        $sign = $document->document_type === 'CreditNote' ? -1 : 1;
        $items = collect($document->lines ?? [])->map(fn ($l) => (object) [
            'product_name' => (string) ($l['description'] ?? 'Item'),
            'quantity' => abs((float) ($l['quantity'] ?? 1)),
            'line_total' => $sign * abs((float) ($l['line_total'] ?? 0)),
            'tax_amount' => $sign * abs((float) ($l['tax_amount'] ?? 0)),
        ]);
        $payments = collect($document->payments ?: [['method' => 'credit', 'amount' => abs((float) $document->total)]])
            ->map(fn ($p) => (object) [
                'method' => (string) ($p['method'] ?? 'credit'),
                'amount' => abs((float) ($p['amount'] ?? 0)),
                'base_equivalent' => null,
            ]);
        $shape = new Transaction([
            'business_id' => $document->business_id,
            'location_id' => $document->location_id,
            'base_currency' => $document->currency_code,
        ]);

        return new FiscalSubject(
            label: 'fiscal document '.$document->id,
            shape: $shape,
            items: $items,
            payments: $payments,
            customer: $document->customer_id ? Customer::find($document->customer_id) : null,
            receiptType: $document->document_type,
            currency: $document->currency_code,
            creditDebitNote: $creditDebitNote,
            receiptNotes: $document->document_type === 'FiscalInvoice' ? null : ($document->reason ?: 'Credit / debit note'),
            setStatus: function (string $status, ?string $receiptId = null, ?string $qr = null, ?string $error = null) use ($document): void {
                $document->update(array_filter([
                    'status' => $status,
                    'fiscal_receipt_id' => $receiptId,
                    'fiscal_qr_code' => $qr,
                    'error_message' => $error,
                ], fn ($v) => $v !== null));
                $this->emitDocumentSyncRecord($document);
            },
        );
    }

    /** Every device learns a document's fiscal outcome. */
    private function emitDocumentSyncRecord(FiscalDocument $document): void
    {
        $document->refresh();
        SyncRecord::create([
            'business_id' => $document->business_id,
            'table_name' => 'fiscal_documents',
            'record_uuid' => $document->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $document->business_id,
                'status' => $document->status,
                'fiscal_receipt_id' => $document->fiscal_receipt_id,
                'fiscal_qr_code' => $document->fiscal_qr_code,
                'error_message' => $document->error_message,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
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
