<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Asset;
use App\Services\Accounting\AssetPostingService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9 / Phase 11d — the asset register. Owner-only: acquiring or
 * disposing of an asset posts directly to the general ledger (see
 * AssetPostingService), the same sensitivity level as manual journal
 * entries. No till involvement anywhere in this controller — an asset is a
 * pure BackOffice/accounting concept, unlike Requisitions or Projects.
 */
class AssetsController extends BackOfficeController
{
    public function __construct(
        private readonly AssetPostingService $postings,
        private readonly BackOfficeAuthorizer $authorizer,
    ) {}

    public function index(): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();

        $assets = Asset::where('business_id', $tenantId)
            ->latest('acquisition_date')
            ->get()
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'asset_number' => $asset->asset_number,
                'name' => $asset->name,
                'category' => $asset->category,
                'status' => $asset->status,
                'acquisition_date' => $asset->acquisition_date->toDateString(),
                'acquisition_cost' => (float) $asset->acquisition_cost,
                'accumulated_depreciation' => $asset->accumulatedDepreciation($tenantId),
                'book_value' => $asset->bookValue($tenantId),
            ]);

        return Inertia::render('BackOffice/Assets', [
            'assets' => $assets,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'asset_number' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'acquisition_date' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lt:acquisition_cost'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'funding_method' => ['required', 'in:cash,bank'],
        ]);

        $asset = Asset::create([
            'business_id' => $this->tenantId(),
            'name' => $data['name'],
            'asset_number' => $data['asset_number'] ?? null,
            'category' => $data['category'] ?? null,
            'notes' => $data['notes'] ?? null,
            'acquisition_date' => $data['acquisition_date'],
            'acquisition_cost' => $data['acquisition_cost'],
            'salvage_value' => $data['salvage_value'] ?? 0,
            'useful_life_months' => $data['useful_life_months'],
            'funding_method' => $data['funding_method'],
            'status' => 'active',
            'created_by_user_id' => $this->userId(),
        ]);

        $this->postings->recordAcquisition($asset);

        return back()->with('success', 'Asset added.');
    }

    public function dispose(Request $request, string $asset): RedirectResponse
    {
        $this->authorize();

        $asset = $this->findOwned($asset);

        abort_if($asset->status !== 'active', 422, 'This asset has already been disposed of.');

        $data = $request->validate([
            'disposed_at' => ['required', 'date'],
            'disposal_proceeds' => ['required', 'numeric', 'min:0'],
        ]);

        $this->postings->recordDisposal($asset, $data['disposed_at'], (float) $data['disposal_proceeds']);

        $asset->update([
            'status' => 'disposed',
            'disposed_at' => $data['disposed_at'],
            'disposal_proceeds' => $data['disposal_proceeds'],
        ]);

        return back()->with('success', 'Asset disposed of.');
    }

    private function findOwned(string $assetId): Asset
    {
        return Asset::where('business_id', $this->tenantId())->findOrFail($assetId);
    }

    private function authorize(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_ASSETS),
            403,
            'Access denied.'
        );
    }
}
