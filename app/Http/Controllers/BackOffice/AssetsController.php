<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AssetsController extends Controller
{
    private const CATEGORIES = [
        'Equipment', 'Furniture & Fixtures', 'Vehicle', 'Electronics & IT',
        'Machinery', 'Building & Property', 'Tools', 'Other',
    ];

    /**
     * Populated from the till (and here) — a lightweight fixed-asset
     * register with book value computed on the fly, same formula as
     * Asset::valuation() / the device's core/assets/depreciation.dart.
     */
    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $status = $request->string('status')->trim()->toString();

        $assets = Asset::where('business_id', $this->tenantId())
            ->whereNull('deleted_at')
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->get();

        $rows = $assets->map(function (Asset $asset) {
            $valuation = $asset->valuation();

            return [
                'id' => $asset->id,
                'name' => $asset->name,
                'category' => $asset->category,
                'asset_tag' => $asset->asset_tag,
                'location_id' => $asset->location_id,
                'purchase_date' => $asset->purchase_date->toDateString(),
                'purchase_cost' => (float) $asset->purchase_cost,
                'salvage_value' => (float) $asset->salvage_value,
                'depreciation_method' => $asset->depreciation_method,
                'useful_life_years' => $asset->useful_life_years,
                'depreciation_rate_percent' => $asset->depreciation_rate_percent !== null
                    ? (float) $asset->depreciation_rate_percent
                    : null,
                'status' => $asset->status,
                'disposed_at' => $asset->disposed_at?->toDateString(),
                'disposal_value' => $asset->disposal_value !== null ? (float) $asset->disposal_value : null,
                'notes' => $asset->notes,
                'book_value' => round($valuation['book_value'], 2),
                'accumulated_depreciation' => round($valuation['accumulated_depreciation'], 2),
            ];
        });

        $undisposed = $rows->where('status', '!=', 'disposed');

        return Inertia::render('BackOffice/Assets', [
            'assets' => $rows->values(),
            'categories' => self::CATEGORIES,
            'filters' => ['status' => $status ?: 'all'],
            'summary' => [
                'count' => $undisposed->count(),
                'total_cost' => round($undisposed->sum('purchase_cost'), 2),
                'total_book_value' => round($undisposed->sum('book_value'), 2),
            ],
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $data = $this->validateAsset($request);

        $this->apply($processor, (string) Str::uuid(), array_merge($data, [
            'business_id' => $this->tenantId(),
            'created_by_user_id' => session('backoffice')['user_id'] ?? null,
            'status' => 'active',
            'created_at' => now()->toIso8601String(),
        ]));

        return back()->with('success', 'Asset added.');
    }

    public function update(Request $request, string $asset, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $existing = Asset::where('business_id', $this->tenantId())->findOrFail($asset);
        $data = $this->validateAsset($request);

        $this->apply($processor, $existing->id, array_merge($data, [
            'business_id' => $existing->business_id,
            'created_by_user_id' => $existing->created_by_user_id,
            'status' => $existing->status,
            'disposed_at' => $existing->disposed_at?->toIso8601String(),
            'disposal_value' => $existing->disposal_value,
            'created_at' => $existing->created_at?->toIso8601String(),
        ]));

        return back()->with('success', 'Asset updated.');
    }

    public function dispose(Request $request, string $asset, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $existing = Asset::where('business_id', $this->tenantId())->findOrFail($asset);

        $data = $request->validate([
            'disposed_at' => ['required', 'date'],
            'disposal_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->apply($processor, $existing->id, [
            'business_id' => $existing->business_id,
            'location_id' => $existing->location_id,
            'created_by_user_id' => $existing->created_by_user_id,
            'name' => $existing->name,
            'category' => $existing->category,
            'asset_tag' => $existing->asset_tag,
            'purchase_date' => $existing->purchase_date->toDateString(),
            'purchase_cost' => $existing->purchase_cost,
            'salvage_value' => $existing->salvage_value,
            'depreciation_method' => $existing->depreciation_method,
            'useful_life_years' => $existing->useful_life_years,
            'depreciation_rate_percent' => $existing->depreciation_rate_percent,
            'status' => 'disposed',
            'disposed_at' => $data['disposed_at'],
            'disposal_value' => $data['disposal_value'] ?? null,
            'notes' => $existing->notes,
            'created_at' => $existing->created_at?->toIso8601String(),
        ]);

        return back()->with('success', 'Asset marked as disposed.');
    }

    public function destroy(string $asset, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $existing = Asset::where('business_id', $this->tenantId())->findOrFail($asset);

        $this->apply($processor, $existing->id, [
            'business_id' => $existing->business_id,
            'location_id' => $existing->location_id,
            'created_by_user_id' => $existing->created_by_user_id,
            'name' => $existing->name,
            'category' => $existing->category,
            'asset_tag' => $existing->asset_tag,
            'purchase_date' => $existing->purchase_date->toDateString(),
            'purchase_cost' => $existing->purchase_cost,
            'salvage_value' => $existing->salvage_value,
            'depreciation_method' => $existing->depreciation_method,
            'useful_life_years' => $existing->useful_life_years,
            'depreciation_rate_percent' => $existing->depreciation_rate_percent,
            'status' => $existing->status,
            'disposed_at' => $existing->disposed_at?->toIso8601String(),
            'disposal_value' => $existing->disposal_value,
            'notes' => $existing->notes,
            'created_at' => $existing->created_at?->toIso8601String(),
            'deleted_at' => now()->toIso8601String(),
        ]);

        return back()->with('success', 'Asset removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAsset(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['required', 'date'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'depreciation_method' => ['required', 'in:none,straight_line,reducing_balance'],
            'useful_life_years' => ['nullable', 'integer', 'min:1'],
            'depreciation_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['salvage_value'] = $data['salvage_value'] ?? 0;
        $data['useful_life_years'] = $data['depreciation_method'] === 'straight_line'
            ? ($data['useful_life_years'] ?? null)
            : null;
        $data['depreciation_rate_percent'] = $data['depreciation_method'] === 'reducing_balance'
            ? ($data['depreciation_rate_percent'] ?? null)
            : null;

        return $data;
    }

    /**
     * Apply through the same SyncProcessor a device push uses, then publish
     * to the sync stream so every device receives it.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(SyncProcessor $processor, string $uuid, array $data): void
    {
        $processor->process('assets', $uuid, 'upsert', $data);

        SyncRecord::create([
            'business_id' => $data['business_id'],
            'table_name' => 'assets',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $data,
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
}
