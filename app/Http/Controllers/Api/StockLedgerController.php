<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockLedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->user()?->device ?? null;
        $businessId = $device?->tenant_id;

        $perPage = min((int) ($request->per_page ?? 50), 200);

        $query = StockMovement::query()
            ->join('products', 'stock_movements.product_id', '=', 'products.id')
            ->join('users', 'stock_movements.user_id', '=', 'users.id')
            ->where('stock_movements.business_id', $businessId)
            ->select([
                'stock_movements.id',
                'stock_movements.product_id',
                'products.name as product_name',
                'products.sku as product_sku',
                'stock_movements.type',
                'stock_movements.quantity_change',
                'stock_movements.unit_cost',
                'stock_movements.running_avg_cost',
                'stock_movements.reason',
                'stock_movements.reference_id',
                'stock_movements.location_id',
                'stock_movements.to_location_id',
                'stock_movements.user_id',
                'users.name as user_name',
                'stock_movements.created_at',
            ])
            ->orderByDesc('stock_movements.created_at');

        if ($request->filled('product_id')) {
            $query->where('stock_movements.product_id', $request->product_id);
        }

        if ($request->filled('type')) {
            $query->where('stock_movements.type', $request->type);
        }

        if ($request->filled('location_id')) {
            $query->where('stock_movements.location_id', $request->location_id);
        }

        if ($request->filled('from')) {
            $query->where('stock_movements.created_at', '>=', Carbon::parse($request->from)->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('stock_movements.created_at', '<=', Carbon::parse($request->to)->endOfDay());
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }
}
