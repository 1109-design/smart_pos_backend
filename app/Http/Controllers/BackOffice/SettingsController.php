<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use App\Services\CatalogueResetService;
use App\Services\StockResetService;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        $this->authorizeOwner();

        $business = Business::find($this->tenantId());
        $resetByUser = $business?->stock_reset_by_user_id ? User::find($business->stock_reset_by_user_id) : null;
        $catalogueResetByUser = $business?->catalogue_reset_by_user_id ? User::find($business->catalogue_reset_by_user_id) : null;

        return Inertia::render('BackOffice/Settings', [
            'stock_reset' => [
                'done' => (bool) $business?->stock_reset_at,
                'at' => $business?->stock_reset_at?->toIso8601String(),
                'by' => $resetByUser?->name,
            ],
            'catalogue_reset' => [
                'done' => (bool) $business?->catalogue_reset_at,
                'at' => $business?->catalogue_reset_at?->toIso8601String(),
                'by' => $catalogueResetByUser?->name,
            ],
        ]);
    }

    /**
     * Zero out every product's stock, everywhere, in one shot. A one-time
     * action, also reachable from a device's own Settings screen (see
     * App\Http\Controllers\Api\StockResetController) — whichever origin gets
     * there first consumes the business's single token via StockResetService,
     * the other is locked out. See [[smartpos-stock-reset]].
     */
    public function resetStock(Request $request, StockResetService $service, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeOwner();

        $request->validate([
            'confirm' => ['required', 'in:RESET'],
        ]);

        $tenantId = $this->tenantId();
        $userId = session('backoffice')['user_id'];

        $claim = $service->claim($tenantId, $userId, session('backoffice')['business_name'] ?? null);

        if (! $claim['claimed']) {
            return back()->withErrors(['stock_reset' => 'Stock has already been reset once for this business. This action can only run a single time.']);
        }

        $resetCount = $service->zeroOutAllStock($tenantId, $userId, $processor);

        return back()->with('success', "Stock reset for {$resetCount} product(s). Devices will receive the change on their next sync. This action cannot be run again.");
    }

    /**
     * Permanently delete the entire product catalogue, every stock record,
     * and all sales/purchase/transfer/stocktake history for this business —
     * a one-time action, independently locked from resetStock() above (this
     * one is strictly more destructive: it deletes rows, including real
     * transactions/payments, rather than only zeroing stock through the
     * ledger). See CatalogueResetService for exactly what it touches and why.
     */
    public function resetCatalogue(Request $request, CatalogueResetService $service): RedirectResponse
    {
        $this->authorizeOwner();

        $request->validate([
            'confirm' => ['required', 'in:DELETE EVERYTHING'],
        ]);

        $tenantId = $this->tenantId();
        $userId = session('backoffice')['user_id'];

        $claim = $service->claim($tenantId, $userId, session('backoffice')['business_name'] ?? null);

        if (! $claim['claimed']) {
            return back()->withErrors(['catalogue_reset' => 'The catalogue has already been reset once for this business. This action can only run a single time.']);
        }

        $counts = $service->resetEverything($tenantId);

        return back()->with(
            'success',
            "Everything cleared: {$counts['products']} product(s), {$counts['transactions']} sale(s), {$counts['purchase_orders']} purchase order(s), {$counts['stock_takes']} stock take(s) and all related records. Connected devices will catch up on their next sync. This action cannot be run again — upload your fresh catalogue whenever you're ready."
        );
    }

    private function authorizeOwner(): void
    {
        if ((session('backoffice')['role'] ?? null) !== 'business_owner') {
            abort(403, 'Only the business owner can do this.');
        }
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
