<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\StockTransfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(private readonly TransferService $transferService) {}

    public function index(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $query = StockTransfer::with(['fromLocation:id,name', 'toLocation:id,name', 'items'])
            ->where('business_id', $device?->tenant_id)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('location_id')) {
            $query->where(fn ($q) => $q
                ->where('from_location_id', $request->location_id)
                ->orWhere('to_location_id', $request->location_id)
            );
        }

        return response()->json(['data' => $query->paginate(30)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $transfer = StockTransfer::with(['fromLocation', 'toLocation', 'items.product', 'requestedBy', 'approvedBy'])
            ->where('business_id', $device?->tenant_id)
            ->findOrFail($id);

        return response()->json(['data' => $transfer]);
    }

    public function store(Request $request): JsonResponse
    {
        $device = $this->resolveDevice($request);

        $data = $request->validate([
            'from_location_id'      => 'required|uuid|exists:locations,id',
            'to_location_id'        => 'required|uuid|exists:locations,id|different:from_location_id',
            'notes'                 => 'nullable|string|max:1000',
            'items'                 => 'required|array|min:1',
            'items.*.product_id'    => 'required|uuid|exists:products,id',
            'items.*.variant_id'    => 'nullable|uuid|exists:product_variants,id',
            'items.*.product_name'  => 'required|string',
            'items.*.qty_requested' => 'required|numeric|min:0.0001',
        ]);

        $transfer = $this->transferService->request([
            ...$data,
            'business_id'          => $device?->tenant_id,
            'requested_by_user_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => $transfer], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $transfer = $this->transferService->approve($id, $request->user()?->id);

        return response()->json(['data' => $transfer]);
    }

    public function dispatch(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.item_id'    => 'required|uuid',
            'items.*.qty_sent'   => 'required|numeric|min:0.0001',
        ]);

        $transfer = $this->transferService->dispatch($id, $data['items'], $request->user()?->id);

        return response()->json(['data' => $transfer]);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items'                   => 'required|array|min:1',
            'items.*.item_id'         => 'required|uuid',
            'items.*.qty_received'    => 'required|numeric|min:0',
        ]);

        $transfer = $this->transferService->receive($id, $data['items'], $request->user()?->id);

        return response()->json(['data' => $transfer]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $transfer = $this->transferService->cancel($id);

        return response()->json(['data' => $transfer]);
    }

    private function resolveDevice(Request $request): ?Device
    {
        return $request->user()?->device ?? null;
    }
}
