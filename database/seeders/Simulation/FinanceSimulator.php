<?php

namespace Database\Seeders\Simulation;

use App\Models\Accounting\GlAccount;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\SalaryPayment;
use App\Models\SyncRecord;
use App\Services\Accounting\AssetPostingService;
use App\Services\Accounting\CashVaultService;
use App\Services\ApprovalService;
use App\Services\SyncProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * FX·06 / FIN·10 / RPT·09 — the back-office money side: exchange rate
 * drift (supervisor-approved, full audit history), monthly payroll, the
 * asset register with its monthly depreciation sweep, day-to-day expenses,
 * cash-vault movements, and the quotation → invoice → payment → credit
 * note document chain.
 */
class FinanceSimulator
{
    private const ASSET_DEFS = [
        ['name' => 'Delivery Van — Toyota Hiace', 'category' => 'Vehicles', 'cost' => 18000, 'life_months' => 60, 'salvage' => 2000, 'day_offset' => 15],
        ['name' => 'Warehouse Forklift', 'category' => 'Equipment', 'cost' => 9500, 'life_months' => 72, 'salvage' => 800, 'day_offset' => 95],
        ['name' => 'POS Till Hardware Bundle (x3)', 'category' => 'Equipment', 'cost' => 1200, 'life_months' => 36, 'salvage' => 0, 'day_offset' => 5],
        ['name' => 'Backup Diesel Generator', 'category' => 'Equipment', 'cost' => 3200, 'life_months' => 48, 'salvage' => 200, 'day_offset' => 170],
        ['name' => 'Office Computers & Printer', 'category' => 'Equipment', 'cost' => 1800, 'life_months' => 36, 'salvage' => 0, 'day_offset' => 240],
    ];

    private bool $assetsScheduled = false;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $pendingAssets = null;

    private ?string $recurringScheduleId = null;

    public function __construct(
        private readonly SyncProcessor $processor,
        private readonly ApprovalService $approvals,
        private readonly AssetPostingService $assetPosting,
        private readonly CashVaultService $cashVault,
    ) {}

    public function forDay(SimContext $context, Carbon $date): void
    {
        $this->scheduleAssetsOnce($date);
        $this->maybeAcquireAsset($context, $date);
        $this->maybeRecurringInvoiceSchedule($context, $date);

        if (SimContext::chance(15)) {
            $this->maybeChangeExchangeRate($context, $date);
        }

        if ($date->day === 25) {
            $this->runPayroll($context, $date);
        }

        if ($date->day === 1) {
            $this->rentExpense($context, $date);
        }

        if (in_array($date->dayOfWeek, [1, 4], true)) {
            $this->routineExpenses($context, $date);
        }

        if ($date->isSaturday()) {
            $this->tillDrop($context, $date);
        }

        if ($date->day === 15 || $date->day === 28) {
            $this->bankDeposit($context, $date);
        }

        if (($date->day === 30 || $date->day === 28) && SimContext::chance(80)) {
            $this->depreciationSweep($context, $date);
        }

        if (SimContext::chance(10)) {
            $this->quotationCycle($context, $date);
        }
    }

    private function scheduleAssetsOnce(Carbon $date): void
    {
        if ($this->assetsScheduled) {
            return;
        }
        $this->assetsScheduled = true;
        $this->pendingAssets = collect(self::ASSET_DEFS)
            ->map(fn ($def) => array_merge($def, ['due' => $date->copy()->addDays($def['day_offset'])->toDateString()]))
            ->all();
    }

    private function maybeAcquireAsset(SimContext $context, Carbon $date): void
    {
        foreach ($this->pendingAssets ?? [] as $key => $def) {
            if ($def['due'] > $date->toDateString()) {
                continue;
            }

            $asset = Asset::create([
                'business_id' => $context->businessId,
                'name' => $def['name'],
                'category' => $def['category'],
                'acquisition_date' => $date->toDateString(),
                'acquisition_cost' => $def['cost'],
                'salvage_value' => $def['salvage'],
                'useful_life_months' => $def['life_months'],
                'funding_method' => 'bank',
                'status' => 'active',
                'created_by_user_id' => $context->ownerUserId,
            ]);
            SimContext::backdate('assets', $asset->id, $date);

            $this->assetPosting->recordAcquisition($asset);

            unset($this->pendingAssets[$key]);
        }
        $this->pendingAssets = array_values($this->pendingAssets ?? []);
    }

    private function maybeChangeExchangeRate(SimContext $context, Carbon $date): void
    {
        $currency = SimContext::pick($context->foreignCurrencies);
        $current = $context->currentRates[$currency];
        $drift = SimContext::between(-0.04, 0.06); // ZWG/ZAR informal rates trend upward more often than not
        $newRate = round(max(1, $current * (1 + $drift)), 4);

        $newRateId = (string) Str::uuid();
        $requester = $this->pickFinanceStaff($context);

        $approval = $this->approvals->request(
            $context->businessId, 'ExchangeRate', $newRateId, 'change_exchange_rate', $requester,
            ['from_currency' => $currency, 'to_currency' => $context->baseCurrency, 'rate' => $newRate, 'locked' => false],
        );
        SimContext::backdate('approval_requests', $approval->id, $date);

        SimContext::withNow($date->copy()->setTime(8, 15), fn () => $this->approvals->resolve($approval->id, $context->ownerUserId, 'approved', 'Rate updated to match parallel market'));

        $context->currentRates[$currency] = $newRate;
    }

    private function runPayroll(SimContext $context, Carbon $date): void
    {
        $period = $date->format('Y-m');
        $employees = Employee::where('business_id', $context->businessId)->where('status', 'active')->get();

        foreach ($employees as $employee) {
            if (SalaryPayment::where('employee_id', $employee->id)->where('period', $period)->exists()) {
                continue;
            }

            $paymentId = (string) Str::uuid();
            $this->syncUpsert('salary_payments', $paymentId, [
                'business_id' => $context->businessId,
                'employee_id' => $employee->id,
                'period' => $period,
                'amount' => (float) $employee->salary_amount,
                'currency_code' => $context->baseCurrency,
                'base_equivalent' => (float) $employee->salary_amount,
                'exchange_rate' => 1,
                'payment_method' => 'bank_transfer',
                'reference' => 'Payroll '.$period,
                'paid_by_user_id' => $context->ownerUserId,
                'paid_at' => $date->copy()->setTime(14, 0)->toIso8601String(),
            ]);
            SimContext::backdate('salary_payments', $paymentId, $date);
        }
    }

    private function rentExpense(SimContext $context, Carbon $date): void
    {
        foreach (['main' => 850, 'chitungwiza' => 650, 'warehouse' => 500] as $locationKey => $amount) {
            $this->expense($context, $date, 'rent', "Monthly rent — {$locationKey}", $amount, 'bank_transfer');
        }
        $this->expense($context, $date, 'utilities', 'ZESA & water — all branches', SimContext::between(220, 420), 'bank_transfer');
    }

    private function routineExpenses(SimContext $context, Carbon $date): void
    {
        $this->expense($context, $date, 'transport', 'Local deliveries & fuel', SimContext::between(20, 70), 'cash');

        if (SimContext::chance(40)) {
            $this->expense($context, $date, 'bank_charges', 'Bank service charges', SimContext::between(4, 18), 'bank_transfer');
        }

        if (SimContext::chance(15)) {
            $this->expense($context, $date, 'cash_withdrawal', 'Owner drawings', SimContext::between(100, 400), 'cash');
        }

        if (SimContext::chance(10)) {
            $this->expense($context, $date, 'stationery', 'Office & till supplies', SimContext::between(10, 45), 'cash');
        }
    }

    private function expense(SimContext $context, Carbon $date, string $category, string $description, float $amount, string $method): void
    {
        $id = (string) Str::uuid();
        $this->syncUpsert('expenses', $id, [
            'business_id' => $context->businessId,
            'recorded_by_user_id' => $context->ownerUserId,
            'category' => $category,
            'description' => $description,
            'amount' => round($amount, 2),
            'currency_code' => $context->baseCurrency,
            'base_equivalent' => round($amount, 2),
            'exchange_rate' => 1,
            'payment_method' => $method,
            'expense_date' => $date->toIso8601String(),
        ]);
    }

    private function tillDrop(SimContext $context, Carbon $date): void
    {
        $cash = GlAccount::where('business_id', $context->businessId)->where('code', '1000')->first()?->balance() ?? 0;
        $drop = round($cash * 0.4, 2);
        if ($drop <= 5) {
            return;
        }

        try {
            $this->cashVault->recordTillDrop($context->businessId, $drop, $date->toDateString(), 'Weekly till drop to safe', $context->ownerUserId);
        } catch (\Throwable) {
            // accounting not live yet — skip
        }
    }

    private function bankDeposit(SimContext $context, Carbon $date): void
    {
        $vault = 0.0;
        try {
            $vault = $this->cashVault->balance($context->businessId);
        } catch (\Throwable) {
            return;
        }

        $deposit = round($vault * 0.7, 2);
        if ($deposit <= 20) {
            return;
        }

        try {
            $this->cashVault->recordBankDeposit($context->businessId, $deposit, $date->toDateString(), 'Vault banked', $context->ownerUserId);
        } catch (\Throwable) {
            // skip
        }
    }

    private function depreciationSweep(SimContext $context, Carbon $date): void
    {
        try {
            $this->assetPosting->postMonthlyDepreciation($context->businessId, $date->toDateString());
        } catch (\Throwable) {
            // no assets live yet — skip
        }
    }

    /**
     * SLS·05 — a quotation that can include items in stock and items that
     * aren't (the shortfall is just data here; the quoted-vs-in-stock
     * report reads it live off product_stock), a visible validity period,
     * and — for the ones a contractor accepts — an invoice with at least a
     * deposit paid against it.
     */
    private function quotationCycle(SimContext $context, Carbon $date): void
    {
        $accountCustomers = array_values(array_filter($context->customers, fn ($c) => $c['is_account']));
        if (empty($accountCustomers)) {
            return;
        }

        $customer = SimContext::pick($accountCustomers);
        $catalogue = array_values(array_filter($context->products, fn ($p) => ! $p['is_sheet']));
        $lineCount = mt_rand(2, 6);
        $picks = (array) array_rand($catalogue, min($lineCount, count($catalogue)));

        $quoteId = (string) Str::uuid();
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $items = [];

        foreach ($picks as $idx) {
            $product = $catalogue[$idx];
            $qty = (float) mt_rand(5, 80); // deliberately can exceed what's on the shelf
            $lineTotal = round($product['price'] * $qty, 2);
            $tax = $customer['tax_exempt'] ? 0.0 : round($lineTotal * 0.15, 2);
            $subtotal += $lineTotal;
            $taxTotal += $tax;

            $items[] = [
                'id' => (string) Str::uuid(), 'business_id' => $context->businessId,
                'product_id' => $product['id'], 'product_name' => $product['name'],
                'quantity' => $qty, 'unit_price' => $product['price'], 'discount_pct' => 0,
                'tax_rate_id' => $context->taxRateId, 'line_total' => round($lineTotal + $tax, 2),
            ];
        }

        $total = round($subtotal + $taxTotal, 2);
        $validUntil = $date->copy()->addDays(14);
        $quoteNumber = 'QUO-'.$date->format('Ymd').'-'.strtoupper(Str::random(4));

        $this->syncUpsert('quotations', $quoteId, [
            'business_id' => $context->businessId,
            'location_id' => $context->locations['main'],
            'customer_id' => $customer['id'],
            'quote_number' => $quoteNumber,
            'status' => 'sent',
            'valid_until' => $validUntil->toIso8601String(),
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'total' => $total,
            'created_by_user_id' => $context->ownerUserId,
            'sent_at' => $date->toIso8601String(),
        ]);
        SimContext::backdate('quotations', $quoteId, $date);

        foreach ($items as $item) {
            $item['quotation_id'] = $quoteId;
            $itemId = $item['id'];
            unset($item['id']);
            $this->syncUpsert('quotation_items', $itemId, $item);
        }

        if (! SimContext::chance(55)) {
            return; // left as an outstanding quote — a fair number just expire unconverted
        }

        $this->syncUpsert('quotations', $quoteId, [
            'business_id' => $context->businessId, 'location_id' => $context->locations['main'],
            'customer_id' => $customer['id'], 'quote_number' => $quoteNumber, 'status' => 'accepted',
            'valid_until' => $validUntil->toIso8601String(), 'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2), 'total' => $total, 'created_by_user_id' => $context->ownerUserId,
            'sent_at' => $date->toIso8601String(), 'accepted_at' => $date->copy()->addDays(mt_rand(1, 5))->toIso8601String(),
        ]);

        $this->convertToInvoice($context, $date, $quoteId, $customer, $items, $subtotal, $taxTotal, $total);
    }

    private function convertToInvoice(SimContext $context, Carbon $date, string $quoteId, array $customer, array $items, float $subtotal, float $taxTotal, float $total): void
    {
        $invoiceId = (string) Str::uuid();
        $issueDate = $date->copy()->addDays(mt_rand(1, 5));
        $dueDate = $issueDate->copy()->addDays(30);

        $this->syncUpsert('invoices', $invoiceId, [
            'business_id' => $context->businessId,
            'location_id' => $context->locations['main'],
            'customer_id' => $customer['id'],
            'quotation_id' => $quoteId,
            'invoice_number' => 'INV-'.$issueDate->format('Ymd').'-'.strtoupper(Str::random(4)),
            'type' => 'standard',
            'status' => 'sent',
            'issue_date' => $issueDate->toIso8601String(),
            'due_date' => $dueDate->toIso8601String(),
            'payment_terms_days' => 30,
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'total' => $total,
            'amount_paid' => 0,
            'created_by_user_id' => $context->ownerUserId,
        ]);
        SimContext::backdate('invoices', $invoiceId, $issueDate);

        foreach ($items as $item) {
            $itemId = (string) Str::uuid();
            $this->syncUpsert('invoice_items', $itemId, [
                'business_id' => $context->businessId,
                'invoice_id' => $invoiceId, 'product_id' => $item['product_id'], 'product_name' => $item['product_name'],
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'discount_pct' => 0,
                'tax_rate_id' => $context->taxRateId, 'line_total' => $item['line_total'],
            ]);
        }

        // Deposit now, balance later — sometimes settled in full same visit.
        $depositShare = SimContext::chance(35) ? 1.0 : SimContext::between(0.3, 0.6);
        $paid = round($total * $depositShare, 2);

        $paymentId = (string) Str::uuid();
        $this->syncUpsert('invoice_payments', $paymentId, [
            'business_id' => $context->businessId,
            'invoice_id' => $invoiceId, 'method' => SimContext::chance(60) ? 'bank_transfer' : 'cash',
            'amount' => $paid, 'currency_code' => $context->baseCurrency, 'base_equivalent' => $paid,
            'recorded_by_user_id' => $context->ownerUserId, 'paid_at' => $issueDate->copy()->addHours(2)->toIso8601String(),
        ]);
        SimContext::backdate('invoice_payments', $paymentId, $issueDate);

        $this->syncUpsert('invoices', $invoiceId, [
            'business_id' => $context->businessId, 'location_id' => $context->locations['main'],
            'customer_id' => $customer['id'], 'quotation_id' => $quoteId,
            'invoice_number' => Invoice::find($invoiceId)->invoice_number,
            'type' => 'standard', 'status' => $paid >= $total ? 'paid' : 'partial',
            'issue_date' => $issueDate->toIso8601String(), 'due_date' => $dueDate->toIso8601String(),
            'payment_terms_days' => 30, 'subtotal' => round($subtotal, 2), 'tax_total' => round($taxTotal, 2),
            'total' => $total, 'amount_paid' => $paid, 'created_by_user_id' => $context->ownerUserId,
        ]);
    }

    /**
     * One standing recurring invoice for a repeat contractor — created
     * once, left to the business's normal monthly billing rhythm.
     */
    private function maybeRecurringInvoiceSchedule(SimContext $context, Carbon $date): void
    {
        if ($this->recurringScheduleId !== null || ! $date->isSameDay($date->copy()->startOfYear()->addDays(9))) {
            return;
        }

        $accountCustomers = array_values(array_filter($context->customers, fn ($c) => $c['is_account']));
        if (empty($accountCustomers)) {
            return;
        }

        $customer = SimContext::pick($accountCustomers);
        $this->recurringScheduleId = (string) Str::uuid();

        $this->syncUpsert('recurring_invoice_schedules', $this->recurringScheduleId, [
            'business_id' => $context->businessId,
            'customer_id' => $customer['id'],
            'template_json' => [
                'notes' => 'Monthly maintenance materials retainer',
                'lines' => [['description' => 'Maintenance materials retainer', 'amount' => 250]],
            ],
            'frequency' => 'monthly',
            'next_run_date' => $date->copy()->addMonth()->toIso8601String(),
            'is_active' => true,
            'created_by_user_id' => $context->ownerUserId,
        ]);
    }

    private function pickFinanceStaff(SimContext $context): string
    {
        $candidates = array_filter($context->staff, fn ($s) => in_array($s['role'], ['manager', 'business_owner'], true));

        return empty($candidates) ? $context->ownerUserId : SimContext::pick(array_values($candidates))['id'];
    }

    /**
     * See MasterDataSeeder::syncUpsert() — process() alone never writes a
     * SyncRecord, so without this every simulated payroll/expense/
     * quotation/invoice/etc. would exist in the database but never reach a
     * device via pull().
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $table, string $uuid, array $payload): void
    {
        $this->processor->process($table, $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? MasterDataSeeder::BUSINESS_ID,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
