<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Product;
use App\Models\SheetLot;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GLS·01's "running yield tracking" — for every sheet-tracked product, one
 * row per physical sheet ever received, showing purchased area vs. cut vs.
 * remaining, so nothing gets lost between the invoice and the offcuts bin.
 * Reads straight off `sheet_lots`/`sheet_cuts`, which the till writes to
 * directly (see CostService.receiveStock() and the POS cut flow) — this
 * controller is read-only.
 */
class SheetYieldController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function __invoke(): Response
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_REQUISITIONS),
            403,
            'Access denied.'
        );

        $tenantId = $this->tenantId();

        $lots = SheetLot::where('business_id', $tenantId)
            ->with(['product:id,name,sku', 'cuts'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SheetLot $lot) {
                $originalArea = $lot->originalArea();
                $cutArea = (float) $lot->cuts->sum('area');

                return [
                    'id' => $lot->id,
                    'product_name' => $lot->product?->name ?? 'Unknown',
                    'product_sku' => $lot->product?->sku,
                    'original_width' => $lot->original_width !== null ? (float) $lot->original_width : null,
                    'original_height' => $lot->original_height !== null ? (float) $lot->original_height : null,
                    'original_area' => $originalArea,
                    'cut_area' => $cutArea,
                    'remaining_area' => (float) $lot->area,
                    'cut_count' => $lot->cuts->count(),
                    'status' => $lot->status,
                    'received_at' => $lot->created_at->toIso8601String(),
                ];
            });

        $productSummaries = Product::where('business_id', $tenantId)
            ->where('item_type', 'sheet')
            ->get(['id', 'name'])
            ->map(fn (Product $product) => [
                'name' => $product->name,
                'lot_count' => $lots->where('product_name', $product->name)->count(),
                'total_remaining_area' => $lots->where('product_name', $product->name)->sum('remaining_area'),
            ]);

        return Inertia::render('BackOffice/SheetYield', [
            'lots' => $lots,
            'product_summaries' => $productSummaries,
        ]);
    }
}
