<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\ProductStock;
use App\Models\Quotation;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SLS·05's "quoted vs in-stock" report — for every still-open quotation
 * (draft/sent, not yet accepted/rejected/expired/converted), compares each
 * line's quoted quantity against CURRENT stock rather than a snapshot taken
 * when the quote was written, so it catches quotes that have become
 * unfulfillable because stock moved after quoting — the till's own
 * shortfall flag (quotation_provider.dart's `QuotationDraftLine.isShortfall`)
 * only ever sees stock at the moment a line was added.
 */
class QuotationStockController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function __invoke(): Response
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_CUSTOMERS),
            403,
            'Access denied.'
        );

        $tenantId = $this->tenantId();

        $quotations = Quotation::with(['items.product:id,name,track_stock,stock_quantity', 'customer:id,name'])
            ->where('business_id', $tenantId)
            ->whereIn('status', ['draft', 'sent'])
            ->orderByDesc('created_at')
            ->get();

        $stockByProductAndLocation = ProductStock::whereIn('product_id', $quotations->pluck('items')->flatten()->pluck('product_id')->unique())
            ->get()
            ->groupBy('product_id');

        $rows = $quotations->flatMap(function (Quotation $quotation) use ($stockByProductAndLocation) {
            return $quotation->items->map(function ($item) use ($quotation, $stockByProductAndLocation) {
                $product = $item->product;
                $tracksStock = $product?->track_stock ?? false;

                $available = null;
                if ($tracksStock) {
                    $locationStock = $quotation->location_id
                        ? $stockByProductAndLocation->get($item->product_id, collect())
                            ->firstWhere('location_id', $quotation->location_id)
                        : null;
                    $available = $locationStock?->quantity ?? $product?->stock_quantity ?? 0;
                }

                $shortfall = $available !== null ? max(0, $item->quantity - $available) : 0;

                return [
                    'quotation_id' => $quotation->id,
                    'quote_number' => $quotation->quote_number,
                    'customer_name' => $quotation->customer?->name ?? 'Walk-in',
                    'status' => $quotation->status,
                    'product_name' => $item->product_name,
                    'quantity_quoted' => (float) $item->quantity,
                    'available_now' => $available !== null ? (float) $available : null,
                    'shortfall' => (float) $shortfall,
                ];
            });
        })
            ->sortByDesc('shortfall')
            ->values();

        return Inertia::render('BackOffice/QuotationStock', [
            'rows' => $rows,
        ]);
    }
}
