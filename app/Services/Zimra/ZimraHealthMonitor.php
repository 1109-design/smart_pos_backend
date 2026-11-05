<?php

namespace App\Services\Zimra;

use App\Models\Business;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\Zimra\ZimraSale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the alert recipients when sales stop being fiscalised. A till's banner
 * only helps someone looking at a till. Sends once for each new problem, a
 * reminder each day it lasts, and once when everything has cleared.
 */
class ZimraHealthMonitor
{
    /** Matches the till's confirmation window: the retry job runs every 15 minutes. */
    private const CONFIRMATION_MINUTES = 20;

    private const LOOKBACK_DAYS = 7;

    private const REMINDER_HOURS = 24;

    private const MAX_LISTED_SALES = 20;

    /**
     * @return array{checked: int, with_problems: int, emails: int}
     */
    public function check(): array
    {
        $results = ['checked' => 0, 'with_problems' => 0, 'emails' => 0];

        foreach (Business::where('fiscalisation_enabled', true)->get() as $business) {
            $results['checked']++;
            $problems = $this->problemsFor($business);
            $results['with_problems'] += $problems->isNotEmpty() ? 1 : 0;
            $results['emails'] += $this->notify($business, $problems) ? 1 : 0;
        }

        return $results;
    }

    /**
     * @return Collection<int, FiscalDocument>
     */
    public function problemsFor(Business $business): Collection
    {
        $settledBy = now()->subMinutes(self::CONFIRMATION_MINUTES);

        return $this->salesWithProblems($business, $settledBy)->toBase()
            ->concat($this->openDocuments(Invoice::class, $business, $settledBy))
            ->concat($this->openDocuments(CreditNote::class, $business, $settledBy))
            ->values();
    }

    /**
     * Invoices and credit notes that were sent and went wrong. Unlike a sale,
     * one with no status at all may simply predate fiscalisation.
     *
     * @param  class-string<Model>  $model
     * @return Collection<int, FiscalDocument>
     */
    private function openDocuments(string $model, Business $business, Carbon $settledBy): Collection
    {
        return $model::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(function ($query) use ($settledBy) {
                $query->whereIn('fiscal_status', ['failed', 'not_configured'])
                    ->orWhere(fn ($stuck) => $stuck->where('fiscal_status', 'pending')->where('created_at', '<', $settledBy));
            })
            ->orderBy('created_at')
            ->get()
            ->toBase();
    }

    /**
     * @return EloquentCollection<int, Transaction>
     */
    private function salesWithProblems(Business $business, Carbon $settledBy): EloquentCollection
    {
        $firstReceiptAt = ZimraSale::where('business_id', $business->id)->min('created_at');

        return Transaction::query()
            ->where('business_id', $business->id)
            ->where('fiscalisation_requested', true)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(function ($query) use ($settledBy, $firstReceiptAt) {
                $query->whereIn('fiscal_status', ['failed', 'not_configured'])
                    ->orWhere(fn ($stuck) => $stuck->where('fiscal_status', 'pending')->where('created_at', '<', $settledBy));

                // A sale that never even reached the queue. Sales from before
                // this business's first receipt were never going to.
                if ($firstReceiptAt !== null) {
                    $query->orWhere(fn ($unsent) => $unsent
                        ->whereNull('fiscal_status')
                        ->where('status', 'completed')
                        ->where('total', '>', 0)
                        ->where('created_at', '>=', $firstReceiptAt)
                        ->where('created_at', '<', $settledBy));
                }
            })
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, FiscalDocument>  $problems
     */
    private function notify(Business $business, Collection $problems): bool
    {
        $stateKey = 'zimra_health_alert_'.$business->id;
        $state = Cache::get($stateKey);
        $ids = $problems->map(fn (Model $document) => $document->getKey())->all();

        if ($problems->isEmpty()) {
            if ($state === null) {
                return false;
            }

            $sent = $this->send(
                'SmartPOS: fiscalisation has recovered for '.$business->name,
                "Every sale and invoice at {$business->name} from the last ".self::LOOKBACK_DAYS.' days is now accounted for.'
            );
            if ($sent) {
                Cache::forget($stateKey);
            }

            return $sent;
        }

        $hasNewProblem = array_diff($ids, $state['ids'] ?? []) !== [];
        $reminderDue = $state !== null && now()->timestamp - $state['mailed_at'] >= self::REMINDER_HOURS * 3600;
        if (! $hasNewProblem && ! $reminderDue) {
            return false;
        }

        Log::error('ZIMRA fiscalisation problem', ['business_id' => $business->id, 'sales' => count($ids)]);

        $sent = $this->send(
            "SmartPOS: {$problems->count()} sale(s) or invoice(s) not fiscalised at {$business->name}",
            $this->body($business, $problems)
        );
        if ($sent) {
            Cache::forever($stateKey, ['ids' => $ids, 'mailed_at' => now()->timestamp]);
        }

        return $sent;
    }

    /**
     * @param  Collection<int, FiscalDocument>  $problems
     */
    private function body(Business $business, Collection $problems): string
    {
        $lines = $problems->take(self::MAX_LISTED_SALES)->map(function (FiscalDocument $sale): string {
            $reason = $sale->fiscal_error ?: match ($sale->fiscal_status) {
                'pending' => 'no answer from ZIMRA yet',
                null => 'never reached the fiscalisation queue',
                default => $sale->fiscal_status,
            };

            return sprintf(
                '- %s  %s %s  %s  %s',
                $sale->fiscalNumber(),
                $sale->fiscalCurrency(),
                number_format((float) $sale->total, 2),
                $sale->created_at?->timezone('Africa/Harare')->format('d M H:i'),
                $reason,
            );
        })->implode("\n");

        $more = $problems->count() - self::MAX_LISTED_SALES;

        return "These sales and invoices at {$business->name} have not been fiscalised:\n\n{$lines}"
            .($more > 0 ? "\n...and {$more} more." : '')
            ."\n\nDetails are in the app under Reports > Fiscalisation Summary.";
    }

    private function send(string $subject, string $body): bool
    {
        $recipients = config('zimra.failure_alert_emails', []);
        if ($recipients === []) {
            return false;
        }

        try {
            Mail::raw($body, fn ($mail) => $mail->to($recipients)->subject($subject));

            return true;
        } catch (Throwable $e) {
            // Not recorded as sent, so the next run tries again.
            Log::error('ZIMRA health alert email failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
