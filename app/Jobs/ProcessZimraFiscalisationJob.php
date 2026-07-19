<?php

namespace App\Jobs;

use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\ZimraSalesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Drains the pending fiscalisation queue for ONE device, strictly one receipt
 * at a time. ShouldBeUnique (keyed by device) ensures a single worker per
 * device — ZIMRA receipt numbering is sequential per device, so concurrent
 * submissions would collide (RCPT012).
 */
class ProcessZimraFiscalisationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 570;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * afterCommit: the sync push wraps each batch in a DB transaction and the
     * receipt's items/payments arrive in that same batch, so the job must not
     * start until the whole batch is committed. (Set here rather than as a
     * typed property — Queueable already declares $afterCommit untyped, and a
     * typed redeclaration is a fatal composition error.)
     */
    public function __construct(public string $deviceId)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return 'zimra-fiscalise-'.$this->deviceId;
    }

    public function handle(ZimraSalesService $service): void
    {
        $pending = ZimraSale::where('device_id', $this->deviceId)
            ->whereIn('status', ['pending', 'retry'])
            ->orderBy('created_at')
            ->get();

        foreach ($pending as $zimraSale) {
            $transaction = $zimraSale->transaction;
            if (! $transaction) {
                continue;
            }

            $result = $service->processQueued($transaction);

            Log::info('ZIMRA sequential fiscalisation processed', [
                'device_id' => $this->deviceId,
                'transaction_id' => $transaction->id,
                'fiscalised' => $result['fiscalised'] ?? false,
                'message' => $result['message'] ?? null,
            ]);

            // Stop draining on infrastructure failure — the retry backoff will
            // re-dispatch; continuing would just fail every remaining receipt.
            if (! ($result['fiscalised'] ?? false) && ! ($result['skipped'] ?? false)) {
                break;
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ZIMRA fiscalisation job failed permanently', [
            'device_id' => $this->deviceId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
