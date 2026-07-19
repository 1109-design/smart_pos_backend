<?php

namespace App\Models\Zimra;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ZimraSale extends Model
{
    protected $fillable = [
        'transaction_id',
        'business_id',
        'tin',
        'device_id',
        'status',
        'zimra_receipt_id',
        'zimra_qr_code',
        'zimra_reference',
        'device_signature_hash',
        'receipt_global_no',
        'fiscal_invoice_no',
        'zimra_response',
        'submitted_receipt',
        'retry_history',
        'error_message',
        'retry_count',
        'zimra_server_date',
        'fiscalised_at',
    ];

    protected function casts(): array
    {
        return [
            'zimra_response' => 'array',
            'submitted_receipt' => 'array',
            'retry_history' => 'array',
            'zimra_server_date' => 'datetime',
            'fiscalised_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isFiscalised(): bool
    {
        return $this->status === 'fiscalised';
    }

    public function hasZimraReceiptEvidence(): bool
    {
        return filled($this->zimra_receipt_id)
            || filled($this->zimra_qr_code)
            || filled(data_get($this->zimra_response, 'receiptID'));
    }

    /**
     * Persist a successful ZIMRA submission: receipt id, server signature,
     * chain hash, and the verification QR code (per ZIMRA spec §6.5).
     */
    public function markAsFiscalised(string $zimraReference, array $response, ?array $originalReceipt = null): void
    {
        $receiptId = $response['receiptID'] ?? null;
        $serverDate = isset($response['serverDate']) ? Carbon::parse($response['serverDate']) : null;

        $qrCodeData = $this->buildQrCode($receiptId, $serverDate, $originalReceipt);

        // hash = SHA-256 of the canonical signing string (chains as previousReceiptHash).
        $deviceSignatureHash = $originalReceipt['receipt']['receiptDeviceSignature']['hash'] ?? null;

        // Persist the exact receipt submitted so a close-day builder can sum these
        // authoritative values instead of recomputing them with newer formatter code.
        $submittedReceipt = $originalReceipt['receipt'] ?? $originalReceipt;

        $this->update([
            'status' => 'fiscalised',
            'zimra_reference' => $zimraReference,
            'zimra_receipt_id' => $receiptId,
            'zimra_qr_code' => $qrCodeData,
            'device_signature_hash' => $deviceSignatureHash,
            'zimra_server_date' => $serverDate,
            'zimra_response' => $response,
            'submitted_receipt' => $submittedReceipt,
            'fiscalised_at' => now(),
        ]);
    }

    /**
     * ZIMRA verification QR (spec §6.5):
     * {verifyUrl}/{deviceId:10d}{receiptDate:ddMMyyyy}{globalNo:10d}{md5(rawDeviceSignatureBytes)[:16]}
     *
     * The MD5 is computed over the base64-DECODED bytes of the receipt's DEVICE
     * signature (the one we generated locally), not the server signature — using
     * the server signature produces the wrong verification code.
     */
    private function buildQrCode(?string $receiptId, ?Carbon $serverDate, ?array $originalReceipt): ?string
    {
        if (! $receiptId || ! $serverDate || ! $this->device_id) {
            return null;
        }

        $config = ZimraConfiguration::getCurrent();
        $baseUrl = $config?->settings['qr_verification_url']
            ?? ($config?->isTest() ? 'https://fdmstest.zimra.co.zw' : 'https://fdms.zimra.co.zw');
        $baseUrl = rtrim($baseUrl, '/');

        $receipt = $originalReceipt['receipt'] ?? $originalReceipt ?? [];

        $receiptDate = isset($receipt['receiptDate'])
            ? Carbon::parse($receipt['receiptDate'])->format('dmY')
            : $serverDate->format('dmY');

        $globalNo = $receipt['receiptGlobalNo'] ?? $receiptId;

        $qrData = '';
        $deviceSignatureBase64 = $receipt['receiptDeviceSignature']['signature'] ?? null;
        if ($deviceSignatureBase64) {
            $qrData = substr(md5(base64_decode($deviceSignatureBase64)), 0, 16);
        }

        $qrCode = $baseUrl.'/'
            .str_pad($this->device_id, 10, '0', STR_PAD_LEFT)
            .$receiptDate
            .str_pad((string) $globalNo, 10, '0', STR_PAD_LEFT)
            .$qrData;

        Log::info('ZIMRA QR code generated', [
            'zimra_sale_id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'qr_code' => $qrCode,
        ]);

        return $qrCode;
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function markAsRetry(): void
    {
        $this->increment('retry_count');
        $this->update(['status' => 'retry']);
    }

    public function appendRetryHistory(array $entry): void
    {
        $history = $this->retry_history ?? [];
        $history[] = array_merge(['attempted_at' => now()->toIso8601String()], $entry);
        $this->update(['retry_history' => $history]);
    }
}
