<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\LocationService;
use App\Services\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransfersController extends Controller
{
    public function __construct(private readonly TransferService $transferService) {}

    public function index(LocationService $locations): Response
    {
        $tenantId = $this->tenantId();
        $locations->ensureDefaultLocation($tenantId);

        $transfers = StockTransfer::with(['fromLocation:id,name', 'toLocation:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'items'])
            ->where('business_id', $tenantId)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/Transfers', [
            'transfers' => $transfers,
            'locations' => Location::where('business_id', $tenantId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'catalog' => Product::where('business_id', $tenantId)
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'string', 'different:to_location_id', 'exists:locations,id'],
            'to_location_id' => ['required', 'string', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'exists:products,id'],
            'items.*.qty_requested' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $this->assertLocationsBelongToTenant([$data['from_location_id'], $data['to_location_id']]);

        $catalog = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->pluck('name', 'id');

        try {
            $this->transferService->request([
                'business_id' => $this->tenantId(),
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'notes' => $data['notes'] ?? null,
                'requested_by_user_id' => $this->userId(),
                'items' => collect($data['items'])->map(fn ($item) => [
                    'product_id' => $item['product_id'],
                    'product_name' => $catalog->get($item['product_id'], 'Unknown item'),
                    'qty_requested' => $item['qty_requested'],
                ])->all(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return back()->with('success', 'Transfer requested.');
    }

    public function approve(string $transfer): RedirectResponse
    {
        $this->findOwned($transfer);

        try {
            $this->transferService->approve($transfer, $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('success', 'Transfer approved.');
    }

    public function dispatch(Request $request, string $transfer): RedirectResponse
    {
        $this->findOwned($transfer);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string'],
            'items.*.qty_sent' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->transferService->dispatch($transfer, $data['items'], $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('success', 'Transfer dispatched — stock reserved at source.');
    }

    public function receive(Request $request, string $transfer): RedirectResponse
    {
        $this->findOwned($transfer);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string'],
            'items.*.qty_received' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->transferService->receive($transfer, $data['items'], $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('success', 'Transfer received — stock updated at both locations.');
    }

    public function cancel(string $transfer): RedirectResponse
    {
        $this->findOwned($transfer);

        try {
            $this->transferService->cancel($transfer);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('success', 'Transfer cancelled.');
    }

    private function findOwned(string $transferId): StockTransfer
    {
        return StockTransfer::where('business_id', $this->tenantId())->findOrFail($transferId);
    }

    /**
     * @param  array<int, string>  $locationIds
     */
    private function assertLocationsBelongToTenant(array $locationIds): void
    {
        $count = Location::where('business_id', $this->tenantId())->whereIn('id', $locationIds)->count();

        abort_if($count !== count(array_unique($locationIds)), 404);
    }

    private function userId(): ?string
    {
        return session('backoffice')['user_id'] ?? null;
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
