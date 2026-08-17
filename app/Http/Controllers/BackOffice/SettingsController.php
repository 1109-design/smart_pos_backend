<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SyncRecord;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
     * action: once run, `businesses.stock_reset_at` locks it out permanently
     * for this business — see Business model / migration.
     *
     * Each product's flat total and per-location split are both ledger-owned
     * (see [[smartpos-project-map]] / SyncProcessor), so "reset" means
     * writing offsetting `stock_movements` entries that bring every number to
     * zero, not a raw UPDATE — that's what lets devices pick the change up on
     * their next sync and keeps the ledger and the totals it derives in
     * agreement, exactly like every other stock-affecting write in this app.
     */
    public function resetStock(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeOwner();

        $request->validate([
            'confirm' => ['required', 'in:RESET'],
        ]);

        $tenantId = $this->tenantId();
        $userId = session('backoffice')['user_id'];

        $business = Business::firstOrCreate(
            ['id' => $tenantId],
            ['name' => session('backoffice')['business_name'] ?? 'Business']
        );

        if ($business->stock_reset_at) {
            return back()->withErrors(['stock_reset' => 'Stock has already been reset once for this business. This action can only run a single time.']);
        }

        $products = Product::where('business_id', $tenantId)
            ->where('item_type', 'product')
            ->get(['id', 'stock_quantity']);

        $stockByProduct = ProductStock::whereIn('product_id', $products->pluck('id'))
            ->where('quantity', '!=', 0)
            ->get(['product_id', 'location_id', 'quantity'])
            ->groupBy('product_id');

        $resetCount = 0;
        foreach ($products as $product) {
            $locationRows = $stockByProduct->get($product->id, collect());

            if ((float) $product->stock_quantity === 0.0 && $locationRows->isEmpty()) {
                continue;
            }

            $remaining = (float) $product->stock_quantity;
            foreach ($locationRows as $row) {
                $this->recordZeroingMovement($processor, $tenantId, $product->id, -(float) $row->quantity, $row->location_id, $userId);
                $remaining -= (float) $row->quantity;
            }

            // Whatever's left after clearing every known location is stock
            // attributed nowhere in particular (e.g. opening stock entered
            // before this business used multi-location) — one more movement
            // with no location_id brings the flat total the rest of the way.
            if (abs($remaining) > 0.0001) {
                $this->recordZeroingMovement($processor, $tenantId, $product->id, -$remaining, null, $userId);
            }

            $resetCount++;
        }

        $business->forceFill([
            'stock_reset_at' => now(),
            'stock_reset_by_user_id' => $userId,
        ])->save();

        return back()->with('success', "Stock reset for {$resetCount} product(s). Devices will receive the change on their next sync. This action cannot be run again.");
    }

    private function recordZeroingMovement(SyncProcessor $processor, string $tenantId, string $productId, float $quantityChange, ?string $locationId, string $userId): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'adjustment',
            'quantity_change' => $quantityChange,
            'reason' => 'Bulk stock reset (one-time) — BackOffice settings',
            'user_id' => $userId,
        ];

        $processor->process('stock_movements', $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'stock_movements',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
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
