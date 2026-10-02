<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\StockTake;
use App\Services\BackOfficeAuthorizer;
use App\Services\StockTakeApprovalService;
use App\Support\BackOfficePermission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockTakesController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $status = $request->string('status')->toString() ?: 'all';

        $stockTakes = $this->scopedStockTakes()
            ->with('location:id,name')
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

        $take = $this->scopedStockTakes()
            ->with(['location:id,name', 'items'])
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
    public function approve(Request $request, string $stockTake, StockTakeApprovalService $approvals): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $take = $this->scopedStockTakes()->with('items')->findOrFail($stockTake);

        try {
            $approvals->approve($take, $this->userId(), $data['review_comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_take' => $e->getMessage()]);
        }

        return redirect()->route('office.stocktakes.index')->with('success', "{$take->title} approved and stock adjusted.");
    }

    public function reject(Request $request, string $stockTake, StockTakeApprovalService $approvals): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $take = $this->scopedStockTakes()->findOrFail($stockTake);

        try {
            $approvals->reject($take, $this->userId(), $data['review_comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_take' => $e->getMessage()]);
        }

        return redirect()->route('office.stocktakes.index')->with('success', "{$take->title} rejected — no stock changed.");
    }

    /**
     * "Send back for correction" — return a submitted count to the counting
     * team instead of outright rejecting it.
     */
    public function reopen(string $stockTake, StockTakeApprovalService $approvals): RedirectResponse
    {
        $this->authorizeManager();

        $take = $this->scopedStockTakes()->findOrFail($stockTake);

        try {
            $approvals->reopen($take, $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_take' => $e->getMessage()]);
        }

        return redirect()->route('office.stocktakes.index')->with('success', "{$take->title} sent back for correction.");
    }

    /**
     * Base query every action uses: this tenant's stock takes, further
     * narrowed to the acting user's location scope when they're restricted
     * to specific branches — a stock take belongs to one location, and this
     * had no scoping at all before, unlike the comparable PurchaseOrders
     * pattern.
     */
    private function scopedStockTakes(): Builder
    {
        $scope = $this->authorizer->currentLocationScope();

        return StockTake::where('business_id', $this->tenantId())
            ->when($scope !== null, fn ($q) => $q->whereIn('location_id', $scope));
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_STOCKTAKES),
            403,
            'Access denied.'
        );
    }
}
