<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\RecurringInvoiceSchedule;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Scheduled daily (see routes/console.php) — generates a new Invoice from
 * each due RecurringInvoiceSchedule's template, then advances next_run_date.
 * Writes go through SyncProcessor::process() + an explicit SyncRecord (same
 * pattern as StockTakeReconcile) so the new invoice actually shows up on
 * devices via the normal pull mechanism, exactly like any other
 * server-originated change.
 *
 * Line-item tax is deliberately left at 0 here — the template only carries
 * a tax_rate_id reference, not a computed amount, since the correct rate
 * lookup belongs in the same place regular invoice editing does it
 * (frontend line-item builder). This is a known simplification for
 * generated recurring invoices, not a general limitation of invoicing.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'invoices:generate-recurring
        {--dry-run : Show what would be generated without writing anything}';

    protected $description = 'Generate invoices from due recurring invoice schedules';

    public function handle(SyncProcessor $processor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        $due = RecurringInvoiceSchedule::where('is_active', true)
            ->whereDate('next_run_date', '<=', $today)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No recurring invoice schedules are due today.');

            return self::SUCCESS;
        }

        $generated = 0;

        foreach ($due as $schedule) {
            $template = $schedule->template_json ?? [];
            $items = $template['items'] ?? [];

            if (empty($items)) {
                $this->warn("Schedule {$schedule->id} has no template items — skipping.");

                continue;
            }

            $this->line(sprintf(
                'Generating invoice for schedule %s (customer %s, %s)%s',
                $schedule->id,
                $schedule->customer_id,
                $schedule->frequency,
                $dryRun ? ' [dry run]' : ''
            ));

            if (! $dryRun) {
                $invoiceId = $this->createInvoice($processor, $schedule, $items);

                $schedule->update([
                    'next_run_date' => $this->nextRunDate($schedule->next_run_date, $schedule->frequency),
                    'last_generated_invoice_id' => $invoiceId,
                ]);

                $this->syncUpsert($schedule->business_id, 'recurring_invoice_schedules', $schedule->id, [
                    'business_id' => $schedule->business_id,
                    'customer_id' => $schedule->customer_id,
                    'template_json' => $schedule->template_json,
                    'frequency' => $schedule->frequency,
                    'next_run_date' => $schedule->next_run_date->toDateString(),
                    'is_active' => $schedule->is_active,
                    'last_generated_invoice_id' => $schedule->last_generated_invoice_id,
                    'created_by_user_id' => $schedule->created_by_user_id,
                ]);
            }

            $generated++;
        }

        $this->info(($dryRun ? 'Would generate ' : 'Generated ')."{$generated} invoice(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createInvoice(SyncProcessor $processor, RecurringInvoiceSchedule $schedule, array $items): string
    {
        $invoiceId = (string) Str::uuid();
        $paymentTermsDays = (int) ($schedule->template_json['payment_terms_days'] ?? 30);
        $issueDate = Carbon::today();
        $dueDate = $issueDate->copy()->addDays($paymentTermsDays);

        $subtotal = 0;
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discountPct = (float) ($item['discount_pct'] ?? 0);
            $subtotal += $quantity * $unitPrice * (1 - $discountPct / 100);
        }

        $invoicePayload = [
            'business_id' => $schedule->business_id,
            'customer_id' => $schedule->customer_id,
            'invoice_number' => $this->nextInvoiceNumber($schedule->business_id),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'payment_terms_days' => $paymentTermsDays,
            'subtotal' => round($subtotal, 4),
            'tax_total' => 0,
            'total' => round($subtotal, 4),
            'recurring_schedule_id' => $schedule->id,
            'notes' => $schedule->template_json['notes'] ?? null,
            'created_by_user_id' => $schedule->created_by_user_id,
        ];

        $processor->process('invoices', $invoiceId, 'upsert', $invoicePayload);
        $this->syncUpsert($schedule->business_id, 'invoices', $invoiceId, $invoicePayload);

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discountPct = (float) ($item['discount_pct'] ?? 0);
            $lineTotal = round($quantity * $unitPrice * (1 - $discountPct / 100), 4);

            $itemId = (string) Str::uuid();
            $itemPayload = [
                'invoice_id' => $invoiceId,
                'business_id' => $schedule->business_id,
                'product_id' => $item['product_id'] ?? null,
                'product_name' => $item['product_name'] ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_pct' => $discountPct,
                'tax_rate_id' => $item['tax_rate_id'] ?? null,
                'line_total' => $lineTotal,
            ];

            $processor->process('invoice_items', $itemId, 'upsert', $itemPayload);
            $this->syncUpsert($schedule->business_id, 'invoice_items', $itemId, $itemPayload);
        }

        return $invoiceId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $businessId, string $table, string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function nextRunDate(Carbon $current, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $current->copy()->addWeek(),
            'quarterly' => $current->copy()->addMonths(3),
            default => $current->copy()->addMonth(), // monthly
        };
    }

    private function nextInvoiceNumber(string $businessId): string
    {
        $prefix = 'INV-'.now()->format('Ym');
        $count = Invoice::where('business_id', $businessId)
            ->where('invoice_number', 'like', "{$prefix}-%")
            ->count();

        return sprintf('%s-%03d', $prefix, $count + 1);
    }
}
