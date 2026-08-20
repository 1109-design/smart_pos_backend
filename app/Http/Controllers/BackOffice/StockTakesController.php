<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StockTakesController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $status = $request->string('status')->toString() ?: 'all';

        $stockTakes = StockTake::with(['location:id,name'])
            ->where('business_id', $tenantId)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/StockTakes', [
            'stock_takes' => $stockTakes,
            'filters' => ['status' => $status],
        ]);
    }

    public function show(string $stockTake): Response
    {
        $this->authorizeManager();

        $take = StockTake::with(['location:id,name', 'items'])
            ->where('business_id', $this->tenantId())
            ->findOrFail($stockTake);

        return Inertia::render('BackOffice/StockTakeShow', [
            'stock_take' => $take,
        ]);
    }

    /**
     * Approve a stock take pending review. This is the one place Back Office
     * genuinely diverges from a device: on the till, "Approve & Apply" both
     * writes the stock_movements adjustments AND flips the status, in one
     * local transaction (see stock_take_report_screen.dart). There is no
     * device present to do that half of the job when approval happens from
     * the web, so this action writes the adjustments itself — through
     * SyncProcessor::process(), never a raw update, so the ledger and the
     * totals derived from it never disagree — then flips the status exactly
     * as the device does. Skipping the movement write here would make
     * approving from the web a silent no-op on actual stock.
     */
    public function approve(Request $request, string $stockTake, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $take = StockTake::with('items')->where('business_id', $this->tenantId())->findOrFail($stockTake);

        $userId = $this->userId();

        try {
            // StockTake::isValidTransition() treats "same status" as a no-op
            // it always allows — by design, for idempotent syncs — so it will
            // NOT catch a second approve on an already-approved take. Without
            // this explicit check, clicking Approve twice would recompute and
            // re-write every item's variance movement a second time.
            if ($take->status !== 'pending_approval') {
                throw new \RuntimeException("{$take->title} is not awaiting approval.");
            }

            $trackedProductIds = Product::whereIn('id', $take->items->pluck('product_id'))
                ->where('track_stock', true)
                ->pluck('id')
                ->all();

            foreach ($take->items as $item) {
                if (! in_array($item->product_id, $trackedProductIds, true)) {
                    continue;
                }

                $counted = $item->counted_qty ?? $item->system_qty;

                // Reconcile against the CURRENT stock at approval time, not
                // the system_qty snapshot captured when the count started —
                // stock can move between then and approval (another
                // device's push landing, etc.), and a stock take must land
                // on exactly what was physically counted, not a stale delta
                // stacked on top of whatever the ledger has drifted to.
                $currentQty = $take->location_id
                    ? (float) StockMovement::where('product_id', $item->product_id)
                        ->where('location_id', $take->location_id)
                        ->sum('quantity_change')
                    : (float) (Product::find($item->product_id)?->stock_quantity ?? 0);

                $variance = (float) $counted - $currentQty;

                if (abs($variance) < 0.0001) {
                    continue;
                }

                $this->recordVarianceMovement($processor, $take, $item->product_id, $variance, $userId);
            }

            $this->applyStockTake($processor, $take, array_merge($this->stockTakePayload($take), [
                'status' => 'approved',
                'approved_by_user_id' => $userId,
                'approved_at' => now()->toIso8601String(),
                'review_comment' => $data['review_comment'] ?? $take->review_comment,
            ]));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_take' => $e->getMessage()]);
        }

        return redirect()->route('office.stocktakes.index')->with('success', "{$take->title} approved and stock adjusted.");
    }

    public function reject(Request $request, string $stockTake, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $take = StockTake::where('business_id', $this->tenantId())->findOrFail($stockTake);

        try {
            if ($take->status !== 'pending_approval') {
                throw new \RuntimeException("{$take->title} is not awaiting approval.");
            }

            $this->applyStockTake($processor, $take, array_merge($this->stockTakePayload($take), [
                'status' => 'rejected',
                'review_comment' => $data['review_comment'] ?? $take->review_comment,
            ]));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_take' => $e->getMessage()]);
        }

        return redirect()->route('office.stocktakes.index')->with('success', "{$take->title} rejected — no stock changed.");
    }

    /**
     * "Send back for correction" — return a submitted count to the counting
     * team instead of outright rejecting it.
     */
    public function reopen(string $stockTake, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $take = StockTake::where('business_id', $this->tenantId())->findOrFail($stockTake);

        try {
            if ($take->status !== 'pending_approval') {
                throw new \RuntimeException("{$take->title} is not awaiting approval.");
            }

            $this->applyStockTake($processor, $take, array_merge($this->stockTakePayload($take), [
                'status' => 'in_progress',
            ]));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_take' => $e->getMessage()]);
        }

        return redirect()->route('office.stocktakes.index')->with('success', "{$take->title} sent back for correction.");
    }

    /**
     * @return array<string, mixed>
     */
    private function stockTakePayload(StockTake $take): array
    {
        return [
            'business_id' => $take->business_id,
            'location_id' => $take->location_id,
            'title' => $take->title,
            'notes' => $take->notes,
            'created_by_user_id' => $take->created_by_user_id,
            'approved_by_user_id' => $take->approved_by_user_id,
            'approved_at' => $take->approved_at?->toIso8601String(),
            'review_comment' => $take->review_comment,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyStockTake(SyncProcessor $processor, StockTake $take, array $payload): void
    {
        $processor->process('stock_takes', $take->id, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $take->business_id,
            'table_name' => 'stock_takes',
            'record_uuid' => $take->id,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function recordVarianceMovement(SyncProcessor $processor, StockTake $take, string $productId, float $variance, ?string $userId): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'business_id' => $take->business_id,
            'location_id' => $take->location_id,
            'product_id' => $productId,
            'type' => 'stocktake',
            'quantity_change' => $variance,
            'reason' => "Stock take: {$take->title}",
            'reference_id' => $take->id,
            'user_id' => $userId,
        ];

        $processor->process('stock_movements', $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $take->business_id,
            'table_name' => 'stock_movements',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function authorizeManager(): void
    {
        abort_if(
            ! in_array(session('backoffice.role'), ['business_owner', 'manager']),
            403,
            'Access denied.'
        );
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }

    private function userId(): ?string
    {
        return session('backoffice')['user_id'] ?? null;
    }
}
