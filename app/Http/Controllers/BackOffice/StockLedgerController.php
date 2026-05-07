<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockLedgerController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $session = session('backoffice');
        $currency = $session['currency_code'] ?? 'USD';
        $tenantId = $session['tenant_id'];

        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to', 'Y-m-d') ?? now()->toDateString();

        $fromStart = Carbon::parse($from)->startOfDay();
        $toEnd = Carbon::parse($to)->endOfDay();

        $query = StockMovement::query()
            ->join('products', 'stock_movements.product_id', '=', 'products.id')
            ->join('users', 'stock_movements.user_id', '=', 'users.id')
            ->where('stock_movements.business_id', $tenantId)
            ->whereBetween('stock_movements.created_at', [$fromStart, $toEnd])
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

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%")
            );
        }

        $movements = $query->paginate(50)->appends($request->query());

        $products = Product::where('business_id', $tenantId)
            ->select('id', 'name', 'sku')
            ->orderBy('name')
            ->get();

        $locations = Location::where('business_id', $tenantId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('BackOffice/StockLedger', [
            'movements' => $movements,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'product_id' => $request->product_id,
                'type' => $request->type,
                'location_id' => $request->location_id,
                'search' => $request->search,
            ],
            'currency' => $currency,
            'products' => $products,
            'locations' => $locations,
        ]);
    }
}
