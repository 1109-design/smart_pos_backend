<?php

namespace App\Services\Zimra;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Business;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ProductTaxRate;
use App\Models\SalesReturn;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraFiscalDay;
use App\Models\Zimra\ZimraSale;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class ZimraSalesService
{
    private const WAIT_AFTER_OPENING_SECONDS = 2;

    public function __construct(
        private ZimraClient $client,
        private FiscalisationGuard $fiscalisationGuard,
        private ZimraReceiptPreflightService $preflight,
    ) {}

    /**
     * Entry point at sale time: records a pending ZimraSale and queues the
     * actual submission for sequential per-device processing. Never blocks the
     * sale itself.
     */
    public function queueFiscalisation(FiscalDocument $document): array
    {
        return $this->processFiscalisation($document, allowSubmit: false);
    }

    /**
     * A return links a till's reversal row to the sale it reverses, and only
     * arrives once that row's items have been pushed, so a credit note is
     * queued from here rather than from the reversal row itself.
     */
    public function queueCreditNoteForReturn(SalesReturn $salesReturn): void
    {
        $reversal = Transaction::find($salesReturn->return_transaction_id);

        if ($reversal && $this->isReversal($reversal) && $reversal->fiscal_status === null) {
            $this->queueFiscalisation($reversal);
        }
    }

    public function isReversal(FiscalDocument $document): bool
    {
        return $document instanceof Transaction && $document->status === 'refunded' && (float) $document->total < 0;
    }

    /**
     * An invoice goes to ZIMRA the moment it is issued, once only: moving on to
     * part-paid or paid never sends it again, and neither does a re-push.
     */
    public function queueInvoiceIfIssued(Invoice $invoice, ?string $previousStatus): void
    {
        $preIssue = [null, 'draft', 'pending_approval', 'approved'];
        $issued = ['sent', 'issued', 'partial', 'paid', 'overdue'];

        if (in_array($previousStatus, $preIssue, true) && in_array($invoice->status, $issued, true) && $invoice->isFiscalisable()) {
            $this->queueFiscalisation($invoice);
        }
    }

    /**
     * Entry point from the queue worker: performs the real ZIMRA submission.
     */
    public function processQueued(FiscalDocument $document): array
    {
        return $this->processFiscalisation($document, allowSubmit: true);
    }

    /**
     * Re-submit every pending/retry sale, oldest first, per device.
     */
    public function retryFailedFiscalisations(): array
    {
        $results = ['attempted' => 0, 'fiscalised' => 0, 'failed' => 0];
        $heldDevices = [];

        $pending = ZimraSale::whereIn('status', ['pending', 'retry'])
            ->orderBy('created_at')
            ->get();

        foreach ($pending as $zimraSale) {
            $document = $zimraSale->document();
            if (! $document || isset($heldDevices[$zimraSale->device_id])) {
                continue;
            }

            $results['attempted']++;
            $result = $this->processQueued($document);
            // One hold covers everything behind it on that device.
            if ($result['held'] ?? false) {
                $heldDevices[$zimraSale->device_id] = true;
            }
            if ($result['fiscalised'] ?? false) {
                $results['fiscalised']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    private function processFiscalisation(FiscalDocument $document, bool $allowSubmit): array
    {
        $docNumber = $document->fiscalNumber();

        if ($document->fiscal_status === 'fiscalised') {
            return ['success' => true, 'message' => 'Already fiscalised', 'fiscalised' => true];
        }

        // Per-business master switch: fiscalisation is opt-in.
        $business = Business::find($document->business_id);
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
        if (! $document->wantsFiscalisation()) {
            if ($document->fiscal_status !== 'excluded') {
                $document->update(['fiscal_status' => 'excluded']);
            }

            return [
                'success' => true,
                'message' => 'Fiscalisation excluded for this sale by the cashier',
                'fiscalised' => false,
                'skipped' => true,
            ];
        }

        // Environment/host guard: refuse to touch live ZIMRA devices from a
        // non-production runtime (e.g. a cloned production database on a dev
        // machine). Skip cleanly so the sale is unaffected.
        if ($guardReason = $this->fiscalisationGuard->blockReason()) {
            Log::warning('ZIMRA fiscalisation skipped — environment guard', [
                'document' => $document->fiscalNumber(),
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
        $locationId = $document->fiscalLocationId();
        $device = ZimraDevice::where('business_id', $document->business_id)
            ->where('is_active', true)
            ->where(function ($query) use ($locationId) {
                if ($locationId) {
                    $query->where('location_id', $locationId)
                        ->orWhereNull('location_id');
                } else {
                    $query->whereNull('location_id');
                }
            })
            ->orderByRaw('location_id IS NULL')
            ->first();

        if (! $device) {
            $document->update(['fiscal_status' => 'not_configured', 'fiscal_error' => 'No active ZIMRA device is set up']);
            $this->emitFiscalSyncRecord($document);

            return [
                'success' => false,
                'message' => 'No active ZIMRA device configured for this business',
                'fiscalised' => false,
            ];
        }

        try {
            $zimraSale = ZimraSale::firstOrNew([$document->zimraKeyColumn() => $document->id]);

            // A receipt ZIMRA has already accepted must never be resubmitted —
            // that would duplicate it in the fiscal chain (RCPT013 at best).
            if ($zimraSale->exists && $zimraSale->hasZimraReceiptEvidence()) {
                return $this->settleFromExistingReceipt($document, $zimraSale);
            }

            $creditNotePlan = $this->isCreditNote($document) ? $this->planCreditNote($document) : null;
            if (in_array($creditNotePlan['state'] ?? null, ['not_applicable', 'failed'], true)) {
                return $this->settleUnsendableCreditNote($document, $zimraSale, $creditNotePlan);
            }

            $zimraSale->fill([
                'business_id' => $document->business_id,
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
                $document->update(['fiscal_status' => 'pending']);

                ProcessZimraFiscalisationJob::dispatch((string) $device->device_id);

                return [
                    'success' => true,
                    'message' => 'Fiscalisation queued',
                    'fiscalised' => false,
                    'queued' => true,
                ];
            }

            if (($creditNotePlan['state'] ?? null) === 'wait' || ! $document->fiscalReadyToSend()) {
                return [
                    'success' => true,
                    'message' => 'Waiting for its lines or its original receipt',
                    'fiscalised' => false,
                    'skipped' => true,
                ];
            }

            if ($badLine = $this->unsendableLine($document)) {
                $zimraSale->markAsFailed($badLine);
                $document->update(['fiscal_status' => 'failed', 'fiscal_error' => $badLine]);
                $this->emitFiscalSyncRecord($document);

                return ['success' => true, 'message' => $badLine, 'fiscalised' => false, 'skipped' => true];
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

            return $this->submitUnderDeviceLock($document, $zimraSale, $device, $taxCodeMap, $submissionInvoiceNo, $creditNotePlan);
        } catch (Exception $e) {
            Log::error('ZIMRA fiscalisation exception', [
                'document' => $document->fiscalNumber(),
                'exception' => $e->getMessage(),
            ]);

            if (isset($zimraSale) && $zimraSale->exists) {
                $zimraSale->markAsRetry();
            }
            $document->update(['fiscal_status' => 'pending']);

            return ['success' => false, 'message' => $e->getMessage(), 'fiscalised' => false];
        }
    }

    private function isCreditNote(FiscalDocument $document): bool
    {
        return $document instanceof CreditNote || $this->isReversal($document);
    }

    /**
     * What to do with a refund's reversal row or an invoice's credit note. ZIMRA only takes a credit note
     * against a receipt it holds, issued within the last 12 months (RCPT032,
     * RCPT033), so a refund of a sale it never accepted can't be sent at all.
     *
     * @return array{state: 'send'|'wait'|'not_applicable'|'failed', reference?: array<string, int>, notes?: string, error?: string}
     */
    private function planCreditNote(FiscalDocument $document): array
    {
        if ($document instanceof CreditNote) {
            if (! $document->fiscalReadyToSend()) {
                return ['state' => 'wait'];
            }
            $originalSale = ZimraSale::where('invoice_id', $document->invoice_id)->first();
            $notes = $document->reason ?: 'Credit note against invoice '.$document->invoice?->invoice_number;
        } else {
            $salesReturn = SalesReturn::where('return_transaction_id', $document->id)->first();
            if (! $salesReturn || $document->fiscalLines()->isEmpty()) {
                return ['state' => 'wait'];
            }
            $originalSale = ZimraSale::where('transaction_id', $salesReturn->original_transaction_id)->first();
            $notes = $salesReturn->reason ?: 'Customer return';
        }

        // Issued before fiscalisation, excluded by the cashier, an old-system
        // sale, or an opening balance: ZIMRA never had it.
        if (! $originalSale) {
            return ['state' => 'not_applicable'];
        }
        if (in_array($originalSale->status, ['pending', 'retry'], true) && ! $originalSale->hasZimraReceiptEvidence()) {
            return ['state' => 'wait'];
        }
        if ($originalSale->isRejectedByZimra() || ! $originalSale->isFiscalised()) {
            return ['state' => 'failed', 'error' => 'The original sale was not accepted by ZIMRA'];
        }
        if ($originalSale->fiscalised_at && $originalSale->fiscalised_at->lt(now()->subMonths(12))) {
            return ['state' => 'failed', 'error' => 'The original sale is over 12 months old, so ZIMRA will not take a credit note'];
        }

        $receiptId = $originalSale->zimra_receipt_id ?: data_get($originalSale->zimra_response, 'receiptID');
        if ($receiptId) {
            $reference = ['receiptID' => (int) $receiptId];
        } elseif ($originalSale->receipt_global_no && $originalSale->fiscal_day_no) {
            // A receipt recovered after a timeout has no ID, but the FDMS spec also
            // accepts the device, global number and day.
            $reference = [
                'deviceID' => (int) $originalSale->device_id,
                'receiptGlobalNo' => (int) $originalSale->receipt_global_no,
                'fiscalDayNo' => (int) $originalSale->fiscal_day_no,
            ];
        } else {
            return ['state' => 'failed', 'error' => 'The original sale has no ZIMRA receipt reference'];
        }

        return [
            'state' => 'send',
            'reference' => $reference,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array{state: string, error?: string}  $plan
     * @return array<string, mixed>
     */
    private function settleUnsendableCreditNote(FiscalDocument $document, ZimraSale $zimraSale, array $plan): array
    {
        $isFailure = $plan['state'] === 'failed';

        if ($zimraSale->exists) {
            $zimraSale->markAsFailed($plan['error'] ?? 'No ZIMRA receipt to credit');
        }
        $document->update([
            'fiscal_status' => $isFailure ? 'failed' : 'not_applicable',
            'fiscal_error' => $plan['error'] ?? null,
        ]);
        $this->emitFiscalSyncRecord($document);

        return [
            'success' => true,
            'message' => $plan['error'] ?? 'The original sale has no ZIMRA receipt, so no credit note is needed',
            'fiscalised' => false,
            'skipped' => true,
        ];
    }

    /**
     * GetStatus → SubmitReceipt → persist chain data as one atomic unit per
     * device. Without the lock, two concurrent submissions read the same
     * lastReceiptGlobalNo and collide (RCPT012); releasing the lock before the
     * signature hash is persisted breaks the previousReceiptHash chain (RCPT020).
     */
    private function submitUnderDeviceLock(FiscalDocument $document, ZimraSale $zimraSale, ZimraDevice $device, array $taxCodeMap, string $invoiceNo, ?array $creditNotePlan = null): array
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
            // Checked again now the lock is held: the app's direct fiscalise
            // call and the queue job both run after a push, and whichever
            // waited on this lock passed the earlier check before the other
            // submitted. Sending it again would be a duplicate (RCPT013).
            $zimraSale->refresh();
            $document->refresh();
            if ($zimraSale->hasZimraReceiptEvidence()) {
                return $this->settleFromExistingReceipt($document, $zimraSale);
            }

            $statusResponse = $this->client->getStatus($device->device_id, $device);

            if (! $statusResponse['success']) {
                $zimraSale->markAsRetry();
                $zimraSale->appendRetryHistory([
                    'invoice_no_used' => $invoiceNo,
                    'result' => 'status_check_failed',
                    'error' => $statusResponse['error'] ?? 'unknown',
                ]);
                $document->update(['fiscal_status' => 'pending']);

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
                    $document->update(['fiscal_status' => 'pending']);

                    return [
                        'success' => true,
                        'message' => 'Fiscalisation pending: could not open fiscal day — '.($openResult['error'] ?? 'unknown'),
                        'fiscalised' => false,
                    ];
                }

                // A receipt dated the same second as the opening draws a Yellow
                // RCPT014, even though it was sent after it.
                Sleep::for(self::WAIT_AFTER_OPENING_SECONDS)->seconds();

                $statusResponse = $this->client->getStatus($device->device_id, $device);
                if (! $statusResponse['success']) {
                    $zimraSale->markAsRetry();
                    $document->update(['fiscal_status' => 'pending']);

                    return ['success' => true, 'message' => 'Fiscalisation pending: status re-check failed after day open', 'fiscalised' => false];
                }
                $statusData = $statusResponse['data'];
            }

            $preflight = $this->preflight->evaluate($device->refresh(), $statusData);
            if (! $preflight['safe']) {
                return $this->holdForPreflight($document, $zimraSale, $invoiceNo, $preflight);
            }

            $receiptGlobalNo = $preflight['next_global_no'];
            $receiptCounter = $preflight['next_counter'];
            $previousHash = $preflight['previous_hash'];

            $fiscalDayNo = ZimraFiscalDay::dayNoFromStatus($statusData);
            if ($fiscalDayNo > 0) {
                ZimraFiscalDay::recordFor($device, $fiscalDayNo);
            }

            $zimraSale->update([
                'receipt_global_no' => $receiptGlobalNo,
                'fiscal_invoice_no' => $invoiceNo,
                'fiscal_day_no' => $fiscalDayNo ?: null,
            ]);

            $items = $document->fiscalLines();
            $payments = $document->fiscalPayments();
            $business = Business::find($document->business_id);
            $customer = $document->fiscalCustomerId() ? Customer::find($document->fiscalCustomerId()) : null;

            $receiptData = ZimraReceiptFormatter::formatReceipt(
                $document,
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
                $invoiceNo,
                $creditNotePlan === null ? null : ['reference' => $creditNotePlan['reference'], 'notes' => $creditNotePlan['notes']],
                $this->lastSubmittedReceiptDate($device),
                $this->exemptProductIds($items)
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
                $document->update([
                    'fiscal_status' => 'fiscalised',
                    'fiscal_error' => null,
                    'fiscal_receipt_number' => 'timeout-recovered-'.$receiptGlobalNo,
                    'fiscal_qr_code' => $zimraSale->zimra_qr_code,
                ]);
                $this->emitFiscalSyncRecord($document);

                return ['success' => true, 'message' => 'Fiscalised (recovered after network timeout)', 'fiscalised' => true];
            }

            $zimraSale->markAsRetry();
            $zimraSale->appendRetryHistory([
                'invoice_no_used' => $invoiceNo,
                'receipt_global_no' => $receiptGlobalNo,
                'result' => 'api_error',
                'error' => $apiResponse['error'] ?? 'ZIMRA unavailable',
            ]);
            $document->update(['fiscal_status' => 'pending']);

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
            // Kept out of the retry queue: the receipt is already in ZIMRA's
            // chain, so sending it again only adds a duplicate.
            $zimraSale->update(['status' => 'rejected', 'error_message' => 'ZIMRA red validation errors: '.$codes]);
            $zimraSale->appendRetryHistory([
                'invoice_no_used' => $invoiceNo,
                'receipt_global_no' => $receiptGlobalNo,
                'result' => 'rejected',
                'error' => $codes,
            ]);
            $document->update(['fiscal_status' => 'failed', 'fiscal_error' => $codes, 'fiscal_receipt_number' => null, 'fiscal_qr_code' => null]);
            $this->emitFiscalSyncRecord($document);

            Log::error('ZIMRA red validation errors', [
                'document' => $document->fiscalNumber(),
                'codes' => $codes,
            ]);

            return [
                'success' => true,
                'message' => 'Receipt accepted into chain with red errors: '.$codes,
                'fiscalised' => false,
                'red_errors' => array_values($redErrors),
            ];
        }

        $document->update([
            'fiscal_status' => 'fiscalised',
            'fiscal_error' => null,
            'fiscal_receipt_number' => $zimraData['receiptID'] ?? null,
            'fiscal_qr_code' => $zimraSale->zimra_qr_code,
        ]);
        $this->emitFiscalSyncRecord($document);

        return [
            'success' => true,
            'message' => 'Fiscalised successfully',
            'fiscalised' => true,
            'zimra_receipt_id' => $zimraData['receiptID'] ?? null,
        ];
    }

    /**
     * Keep the document queued without sending it. Nothing is consumed: no retry
     * count, invoice number or global number.
     *
     * @param  array{reason: string|null, message: string|null}  $preflight
     * @return array<string, mixed>
     */
    private function holdForPreflight(FiscalDocument $document, ZimraSale $zimraSale, string $invoiceNo, array $preflight): array
    {
        $zimraSale->update(['status' => 'pending', 'error_message' => $preflight['message']]);
        $zimraSale->appendRetryHistory([
            'invoice_no_used' => $invoiceNo,
            'result' => 'held',
            'error' => $preflight['reason'],
        ]);
        $document->update(['fiscal_status' => 'pending', 'fiscal_error' => $preflight['message']]);
        if ($document->wasChanged('fiscal_error')) {
            $this->emitFiscalSyncRecord($document);
        }

        Log::warning('ZIMRA receipt held by preflight', [
            'document' => $document->fiscalNumber(),
            'reason' => $preflight['reason'],
        ]);

        return ['success' => true, 'message' => $preflight['message'], 'fiscalised' => false, 'held' => true];
    }

    /**
     * ZIMRA marks a zero or wrongly signed sale line Red (RCPT022), which would
     * stop the whole day closing. Such a document can never be sent as it stands.
     */
    private function unsendableLine(FiscalDocument $document): ?string
    {
        $isCreditNote = $this->isCreditNote($document);

        foreach ($document->fiscalLines() as $line) {
            $total = (float) $line->line_total;
            if ($isCreditNote ? round(abs($total), 2) == 0 : round($total, 2) <= 0) {
                return 'A line has no price ('.($line->product_name ?: 'Item').'), which ZIMRA rejects (RCPT022).';
            }
        }

        return null;
    }

    /**
     * The lines' products that are on the Exempt rate from the tax settings.
     *
     * @param  Collection<int, object>  $items
     * @return array<int, string>
     */
    private function exemptProductIds(Collection $items): array
    {
        return ProductTaxRate::query()
            ->join('tax_rates', 'tax_rates.id', '=', 'product_tax_rates.tax_rate_id')
            ->whereIn('product_tax_rates.product_id', $items->pluck('product_id')->filter()->unique())
            ->where('tax_rates.name', 'like', '%exempt%')
            ->pluck('product_tax_rates.product_id')
            ->all();
    }

    private function lastSubmittedReceiptDate(ZimraDevice $device): ?string
    {
        return ZimraSale::where('device_id', (string) $device->device_id)
            ->whereNotNull('submitted_receipt')
            ->orderByDesc('receipt_global_no')
            ->first()?->submitted_receipt['receiptDate'] ?? null;
    }

    /**
     * This sale already has a receipt at ZIMRA, so never submit it again; bring
     * the sale's status into line with what ZIMRA said about that receipt.
     *
     * @return array<string, mixed>
     */
    private function settleFromExistingReceipt(FiscalDocument $document, ZimraSale $zimraSale): array
    {
        if ($zimraSale->isRejectedByZimra()) {
            $codes = $zimraSale->redValidationCodes();
            $zimraSale->update(['status' => 'rejected']);
            $document->update(['fiscal_status' => 'failed', 'fiscal_error' => implode(', ', $codes), 'fiscal_receipt_number' => null, 'fiscal_qr_code' => null]);
            if ($document->wasChanged('fiscal_status')) {
                $this->emitFiscalSyncRecord($document);
            }

            return [
                'success' => true,
                'message' => 'Already rejected by ZIMRA: '.implode(', ', $codes),
                'fiscalised' => false,
                'red_errors' => $codes,
            ];
        }

        $zimraSale->update(['status' => 'fiscalised']);
        $document->update([
            'fiscal_status' => 'fiscalised',
            'fiscal_error' => null,
            'fiscal_receipt_number' => $zimraSale->zimra_receipt_id,
            'fiscal_qr_code' => $zimraSale->zimra_qr_code,
        ]);
        if ($document->wasChanged('fiscal_status')) {
            $this->emitFiscalSyncRecord($document);
        }

        return ['success' => true, 'message' => 'Already accepted by ZIMRA', 'fiscalised' => true];
    }

    /**
     * Publish the document's fiscal outcome to the sync stream (device_id = null →
     * every device pulls it) so POS devices learn the QR code and fiscal receipt
     * number for re-prints and transaction history.
     */
    private function emitFiscalSyncRecord(FiscalDocument $document): void
    {
        $document->refresh();

        SyncRecord::create([
            'business_id' => $document->business_id,
            'table_name' => $document->getTable(),
            'record_uuid' => $document->id,
            'operation' => 'upsert',
            'payload' => $document->fiscalSyncPayload(),
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
