<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
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

        return Inertia::render('BackOffice/Settings', [
            'stock_reset' => [
                'done' => (bool) $business?->stock_reset_at,
                'at' => $business?->stock_reset_at?->toIso8601String(),
                'by' => $resetByUser?->name,
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
