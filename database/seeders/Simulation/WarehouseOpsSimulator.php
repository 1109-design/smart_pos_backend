<?php

namespace Database\Seeders\Simulation;

use App\Models\Product;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\SyncRecord;
use App\Services\Accounting\StockTakePostingService;
use App\Services\ProjectService;
use App\Services\RequisitionService;
use App\Services\SyncProcessor;
use App\Services\TransferService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * STK·03 / PRJ·04 / STC·08 — everything that moves stock inside the
 * business rather than out the door to a customer: warehouse→shop
 * replenishment transfers, requisitions (general use and project jobs),
 * the two or three site projects running through the year, and each
 * location's monthly stock take.
 */
class WarehouseOpsSimulator
{
    private const PROJECT_DEFS = [
        ['name' => 'Borrowdale Show House Renovation', 'reference' => 'SITE-BD-01', 'start_offset' => 20, 'duration_days' => 70, 'budget' => 6500],
        ['name' => 'St. Mary\'s Mission Classroom Block', 'reference' => 'SITE-STM-02', 'start_offset' => 130, 'duration_days' => 90, 'budget' => 9000],
        ['name' => 'Chitungwiza Depot Extension', 'reference' => 'SITE-CHZ-03', 'start_offset' => 220, 'duration_days' => 60, 'budget' => 5200],
    ];

    /** @var array<int, array{id: string, name: string, closes_on: string}> */
    private array $activeProjects = [];

    private bool $projectsScheduled = false;

    public function __construct(
        private readonly SyncProcessor $processor,
        private readonly TransferService $transfers,
        private readonly RequisitionService $requisitions,
        private readonly ProjectService $projects,
        private readonly StockTakePostingService $stockTakePosting,
    ) {}

    public function forDay(SimContext $context, Carbon $date): void
    {
        $this->maybeStartOrCloseProjects($context, $date);

        if ($date->isSunday()) {
            return;
        }

        if (SimContext::chance(50)) {
            $this->replenishmentTransfer($context, $date);
        }

        if (SimContext::chance(20)) {
            $this->generalRequisition($context, $date);
        }

        if (! empty($this->activeProjects) && SimContext::chance(35)) {
            $this->projectActivity($context, $date);
        }

        if ($date->day === 28) {
            $this->runStockTakes($context, $date);
        }
    }

    private function maybeStartOrCloseProjects(SimContext $context, Carbon $date): void
    {
        if (! $this->projectsScheduled) {
            $this->projectsScheduled = true;
            $this->pendingProjectStarts = collect(self::PROJECT_DEFS)
                ->map(fn ($def) => array_merge($def, ['starts_on' => $date->copy()->addDays($def['start_offset'])->toDateString()]))
                ->all();
        }

        foreach ($this->pendingProjectStarts ?? [] as $key => $def) {
            if ($def['starts_on'] > $date->toDateString()) {
                continue;
            }

            $project = SimContext::withNow($date->copy()->setTime(8, 0), fn () => $this->projects->create([
                'business_id' => $context->businessId,
                'name' => $def['name'],
                'reference' => $def['reference'],
                'created_by_user_id' => $context->ownerUserId,
                'budget' => $def['budget'],
            ]));

            $this->activeProjects[] = [
                'id' => $project->id, 'name' => $project->name,
                'closes_on' => $date->copy()->addDays($def['duration_days'])->toDateString(),
            ];
            unset($this->pendingProjectStarts[$key]);
        }

        foreach ($this->activeProjects as $key => $active) {
            if ($active['closes_on'] > $date->toDateString()) {
                continue;
            }

            SimContext::withNow($date->copy()->setTime(16, 0), fn () => $this->projects->close($active['id']));
            unset($this->activeProjects[$key]);
        }
        $this->activeProjects = array_values($this->activeProjects);
    }

    /** @var array<int, array<string, mixed>>|null */
    private ?array $pendingProjectStarts = null;

    private function replenishmentTransfer(SimContext $context, Carbon $date): void
    {
        $to = SimContext::pick(['main', 'chitungwiza']);
        $trackable = array_values(array_filter($context->products, fn ($p) => $p['track_stock']));
        $picks = (array) array_rand($trackable, min(7, count($trackable)));

        $items = [];
        foreach ($picks as $idx) {
            $product = $trackable[$idx];
            $available = $this->availableAt($product['id'], $context->locations['warehouse']);
            if ($available < 10) {
                continue;
            }
            $items[] = ['product_id' => $product['id'], 'product_name' => $product['name'], 'qty_requested' => (float) min($available, mt_rand(20, 120))];
        }

        if (empty($items)) {
            return;
        }

        $manager = $this->pickManager($context, $to) ?? $context->ownerUserId;
        $moment = $date->copy()->setTime(9, 0);

        $transfer = SimContext::withNow($moment, fn () => $this->transfers->request([
            'business_id' => $context->businessId,
            'from_location_id' => $context->locations['warehouse'],
            'to_location_id' => $context->locations[$to],
            'requested_by_user_id' => $manager,
            'notes' => 'Weekly branch replenishment',
            'items' => $items,
        ]));

        SimContext::withNow($moment->copy()->addHour(), fn () => $this->transfers->approve($transfer->id, $context->ownerUserId));

        $dispatchQtys = $transfer->items->map(fn ($i) => ['item_id' => $i->id, 'qty_sent' => (float) $i->qty_requested])->all();
        SimContext::withNow($date->copy()->setTime(10, 0), fn () => $this->transfers->dispatch($transfer->id, $dispatchQtys, $manager));

        $receiveQtys = $transfer->items->map(fn ($i) => ['item_id' => $i->id, 'qty_received' => (float) $i->qty_requested])->all();
        SimContext::withNow($date->copy()->addDay()->setTime(8, 30), fn () => $this->transfers->receive($transfer->id, $receiveQtys, $manager));
    }

    private function generalRequisition(SimContext $context, Carbon $date): void
    {
        $to = SimContext::pick(['main', 'chitungwiza']);
        $consumables = array_values(array_filter($context->products, fn ($p) => $p['track_stock']
            && in_array($p['category'], ['Safety Gear', 'Fasteners & Fixings', 'Paint & Chemicals'], true)));
        if (empty($consumables)) {
            return;
        }

        $product = SimContext::pick($consumables);
        $manager = $this->pickManager($context, $to) ?? $context->ownerUserId;
        $moment = $date->copy()->setTime(9, 30);

        $requisition = SimContext::withNow($moment, fn () => $this->requisitions->request([
            'business_id' => $context->businessId,
            'location_id' => $context->locations['warehouse'],
            'requested_by_user_id' => $manager,
            'purpose' => 'general',
            'notes' => 'General use — branch consumables',
            'items' => [['product_id' => $product['id'], 'product_name' => $product['name'], 'quantity_requested' => (float) mt_rand(3, 15)]],
        ]));

        SimContext::withNow($moment->copy()->addHour(), fn () => $this->requisitions->approve($requisition->id, $context->ownerUserId));
        SimContext::withNow($moment->copy()->addHours(2), fn () => $this->requisitions->issue($requisition->id, [], $context->ownerUserId));
    }

    private function projectActivity(SimContext $context, Carbon $date): void
    {
        $project = SimContext::pick($this->activeProjects);
        $buildMaterials = array_values(array_filter($context->products, fn ($p) => $p['track_stock']
            && in_array($p['category'], ['Cement & Building Materials', 'Timber & Boards', 'Roofing Sheets', 'Plumbing', 'Electrical'], true)));
        if (empty($buildMaterials)) {
            return;
        }

        $moment = $date->copy()->setTime(8, 0);
        $lineCount = mt_rand(1, 3);
        $items = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $product = SimContext::pick($buildMaterials);
            $items[] = ['product_id' => $product['id'], 'product_name' => $product['name'], 'quantity_requested' => (float) mt_rand(5, 40)];
        }

        $requisition = SimContext::withNow($moment, fn () => $this->requisitions->request([
            'business_id' => $context->businessId,
            'location_id' => $context->locations['warehouse'],
            'requested_by_user_id' => $context->ownerUserId,
            'purpose' => 'project',
            'project_id' => $project['id'],
            'notes' => "Materials for {$project['name']}",
            'items' => $items,
        ]));

        SimContext::withNow($moment->copy()->addHour(), fn () => $this->requisitions->approve($requisition->id, $context->ownerUserId));
        SimContext::withNow($moment->copy()->addHours(3), fn () => $this->requisitions->issue($requisition->id, [], $context->ownerUserId));

        // Project expenses — transport bringing materials in, labour for
        // loading/offloading — captured directly against the job (PRJ·04).
        foreach ([
            ['category' => 'transport', 'amount' => SimContext::between(25, 80), 'description' => 'Delivery to site'],
            ['category' => 'labour', 'amount' => SimContext::between(15, 60), 'description' => 'Loading/offloading labour'],
        ] as $expense) {
            $this->syncUpsert('expenses', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'recorded_by_user_id' => $context->ownerUserId,
                'project_id' => $project['id'],
                'category' => $expense['category'],
                'description' => $expense['description'].' — '.$project['name'],
                'amount' => $expense['amount'],
                'currency_code' => $context->baseCurrency,
                'base_equivalent' => $expense['amount'],
                'exchange_rate' => 1,
                'payment_method' => 'cash',
                'expense_date' => $date->toIso8601String(),
            ]);
        }
    }

    private function runStockTakes(SimContext $context, Carbon $date): void
    {
        foreach ($context->locations as $key => $locationId) {
            $this->runStockTakeForLocation($context, $date, $key, $locationId);
        }
    }

    private function runStockTakeForLocation(SimContext $context, Carbon $date, string $locationKey, string $locationId): void
    {
        $trackable = array_values(array_filter($context->products, fn ($p) => $p['track_stock']));
        $sample = collect($trackable)->shuffle()->take(min(20, count($trackable)));
        $manager = $this->pickManager($context, $locationKey) ?? $context->ownerUserId;

        $stockTakeId = (string) Str::uuid();
        $title = ucfirst($locationKey).' stock take — '.$date->format('F Y');

        $this->syncUpsert('stock_takes', $stockTakeId, [
            'business_id' => $context->businessId, 'location_id' => $locationId,
            'title' => $title, 'status' => 'draft', 'created_by_user_id' => $manager,
        ]);

        $this->syncUpsert('stock_takes', $stockTakeId, [
            'business_id' => $context->businessId, 'location_id' => $locationId,
            'title' => $title, 'status' => 'in_progress', 'created_by_user_id' => $manager,
        ]);

        foreach ($sample as $product) {
            $systemQty = $this->availableAt($product['id'], $locationId);
            $bigMiss = SimContext::chance(6);
            $variance = $bigMiss ? SimContext::between(0.08, 0.20) * (SimContext::chance(50) ? 1 : -1) : SimContext::between(-0.03, 0.03);
            $counted = max(0, round($systemQty * (1 + $variance)));

            $itemId = (string) Str::uuid();
            $this->syncUpsert('stock_take_items', $itemId, [
                'business_id' => $context->businessId, 'stock_take_id' => $stockTakeId,
                'product_id' => $product['id'], 'product_name' => $product['name'],
                'system_qty' => $systemQty, 'counted_qty' => $counted,
            ]);

            // Flagged for a mandatory recount (STC·08) — resubmit a
            // corrected count before this take can be approved.
            if (StockTakeItem::find($itemId)?->flagged_for_recount) {
                $recount = max(0, round($systemQty * (1 + SimContext::between(-0.02, 0.02))));
                $this->syncUpsert('stock_take_items', $itemId, [
                    'business_id' => $context->businessId, 'stock_take_id' => $stockTakeId,
                    'product_id' => $product['id'], 'product_name' => $product['name'],
                    'system_qty' => $systemQty, 'counted_qty' => $recount,
                ]);
            }
        }

        $this->syncUpsert('stock_takes', $stockTakeId, [
            'business_id' => $context->businessId, 'location_id' => $locationId,
            'title' => $title, 'status' => 'pending_approval', 'created_by_user_id' => $manager,
        ]);

        $this->approveStockTake($context, $stockTakeId, $date, $locationId);
    }

    /**
     * Mirrors StockTakesController::approve() — reconciling counted vs
     * current stock and posting the variance movements is a BackOffice web
     * action in the real app (no device is present to do it locally), so
     * this reproduces that same logic rather than pretending a till did it.
     */
    private function approveStockTake(SimContext $context, string $stockTakeId, Carbon $date, string $locationId): void
    {
        $take = StockTake::with('items')->find($stockTakeId);
        if (! $take || $take->status !== 'pending_approval') {
            return;
        }

        $trackedProductIds = Product::whereIn('id', $take->items->pluck('product_id'))->where('track_stock', true)->pluck('id')->all();
        $approvedAt = $date->copy()->setTime(15, 0);

        foreach ($take->items as $item) {
            if (! in_array($item->product_id, $trackedProductIds, true)) {
                continue;
            }

            $counted = $item->counted_qty ?? $item->system_qty;
            $currentQty = (float) StockMovement::where('product_id', $item->product_id)->where('location_id', $locationId)->sum('quantity_change');
            $variance = (float) $counted - $currentQty;

            if (abs($variance) < 0.0001) {
                continue;
            }

            $movementId = (string) Str::uuid();
            $this->syncUpsert('stock_movements', $movementId, [
                'business_id' => $context->businessId, 'location_id' => $locationId,
                'product_id' => $item->product_id, 'type' => 'stocktake', 'quantity_change' => $variance,
                'reason' => "Stock take: {$take->title}", 'reference_id' => $take->id,
                'user_id' => $context->ownerUserId,
            ]);
            SimContext::backdate('stock_movements', $movementId, $approvedAt);
            SimContext::fixJournalDate('stock_take_variance', $movementId, $date);
        }

        $this->syncUpsert('stock_takes', $stockTakeId, [
            'business_id' => $context->businessId, 'location_id' => $locationId,
            'title' => $take->title, 'status' => 'approved',
            'created_by_user_id' => $take->created_by_user_id,
            'approved_by_user_id' => $context->ownerUserId,
            'approved_at' => $approvedAt->toIso8601String(),
        ]);
        SimContext::backdate('stock_takes', $stockTakeId, $date);
    }

    private function availableAt(string $productId, string $locationId): float
    {
        return max(0, (float) StockMovement::where('product_id', $productId)->where('location_id', $locationId)->sum('quantity_change'));
    }

    private function pickManager(SimContext $context, string $locationKey): ?string
    {
        $candidates = array_filter($context->staff, fn ($s) => in_array($s['role'], ['manager', 'business_owner'], true) && $s['location'] === $locationKey);
        if (empty($candidates)) {
            $candidates = array_filter($context->staff, fn ($s) => in_array($s['role'], ['manager', 'business_owner'], true));
        }

        return empty($candidates) ? null : SimContext::pick(array_values($candidates))['id'];
    }

    /**
     * See MasterDataSeeder::syncUpsert() — process() alone never writes a
     * SyncRecord, so without this every simulated stock take/expense/
     * movement would exist in the database but never reach a device via
     * pull().
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
