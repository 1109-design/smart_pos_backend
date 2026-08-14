<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocationController extends Controller
{
    public function __construct(private readonly LocationService $locationService) {}

    public function index(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);
        $businessId = $device?->tenant_id;

        $locations = Location::where('business_id', $businessId)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:shop,warehouse',
            'parent_id' => 'nullable|uuid|exists:locations,id',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email',
            'can_sell' => 'boolean',
            'can_receive' => 'boolean',
        ]);

        $location = Location::create([
            'id' => Str::uuid(),
            'business_id' => $device?->tenant_id,
            ...$data,
        ]);

        return response()->json(['data' => $location], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $location = Location::where('business_id', $device?->tenant_id)
            ->findOrFail($id);

        return response()->json(['data' => $location]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $location = Location::where('business_id', $device?->tenant_id)->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:shop,warehouse',
            'parent_id' => 'nullable|uuid|exists:locations,id',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email',
            'can_sell' => 'boolean',
            'can_receive' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $location->update($data);

        return response()->json(['data' => $location->fresh()]);
    }

    /**
     * Stock levels across all products at a location.
     */
    public function stock(Request $request, string $id): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $location = Location::where('business_id', $device?->tenant_id)->findOrFail($id);

        $stock = $location->productStock()->with('product:id,name,sku,barcode,track_stock')->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'product_name' => $row->product?->name,
                'sku' => $row->product?->sku,
                'barcode' => $row->product?->barcode,
                'quantity' => (float) $row->quantity,
                'reserved' => (float) $row->reserved_quantity,
                'available' => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
            ]);

        return response()->json(['data' => $stock]);
    }

    /**
     * Stock level for a single product across all locations.
     */
    public function productStock(Request $request, string $productId): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $product = Product::where('business_id', $device?->tenant_id)->findOrFail($productId);

        $breakdown = $this->locationService->stockByLocation($product->id);

        return response()->json(['data' => $breakdown]);
    }

    private function resolveDevice(Request $request): ?Device
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $tokenId = explode('|', $token)[0] ?? null;

        return Device::where('token_id', $tokenId)->first();
    }
}
