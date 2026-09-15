<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Business;
use App\Models\User;
use App\Services\BackOfficeAuthorizer;
use App\Services\BusinessBrandingService;
use App\Services\CatalogueResetService;
use App\Services\StockResetService;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

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
            'workflows' => [
                'stock_transfer_requires_approval' => $business?->workflowRequiresApproval('stock_transfer_requires_approval') ?? false,
                'po_approval_threshold' => $business?->poApprovalThreshold(),
                'stock_take_variance_threshold_percent' => $business?->stockTakeVarianceThresholdPercent(),
            ],
            'branding' => [
                'business_name' => $business?->name,
                'primary_color' => $business?->primary_color,
                'logo_url' => $business?->logoUrl(),
            ],
        ]);
    }

    /**
     * Sets the tenant's brand color, shown across the BackOffice sidebar and
     * threaded to devices via Business::publishBrandingSyncRecord(). See
     * BusinessBrandingService for why this and the logo below never touch
     * the generic 'businesses' sync payload.
     */
    public function updateBranding(Request $request, BusinessBrandingService $service): RedirectResponse
    {
        $this->authorizeOwner();

        $data = $request->validate([
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $business = Business::find($this->tenantId());
        if ($business) {
            $service->updateColor($business, $data['primary_color'] ?? null);
            $this->refreshSessionBranding($business);
        }

        return back()->with('success', 'Branding updated.');
    }

    /** Logo upload is a separate action from updateBranding() above so the color picker and file input can be submitted independently. */
    public function uploadBrandingLogo(Request $request, BusinessBrandingService $service): RedirectResponse
    {
        $this->authorizeOwner();

        $data = $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $business = Business::find($this->tenantId());
        if ($business) {
            $service->uploadLogo($business, $data['logo']);
            $this->refreshSessionBranding($business);
        }

        return back()->with('success', 'Logo updated.');
    }

    /** Keeps the currently logged-in owner's session in sync so a branding change is visible immediately, without re-login (mirrors business_name's existing session caching in SessionController::store()). */
    private function refreshSessionBranding(Business $business): void
    {
        session([
            'backoffice.primary_color' => $business->primary_color,
            'backoffice.logo_url' => $business->logoUrl(),
        ]);
    }

    /**
     * Opt-in per-business workflow toggles — see Business::workflowRequiresApproval()
     * and TransferService::dispatch() for the one currently wired up. Each
     * toggle is posted independently by the frontend (one field per
     * request), so every key here is optional rather than required.
     */
    public function updateWorkflowSettings(Request $request): RedirectResponse
    {
        $this->authorizeOwner();

        $data = $request->validate([
            'stock_transfer_requires_approval' => ['sometimes', 'boolean'],
            'po_approval_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_take_variance_threshold_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $business = Business::find($this->tenantId());
        $business?->update([
            'workflow_settings' => array_merge($business->workflow_settings ?? [], $data),
        ]);

        return back()->with('success', 'Workflow settings updated.');
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
        abort_unless($this->authorizer->isBusinessOwner(), 403, 'Only the business owner can do this.');
    }
}
