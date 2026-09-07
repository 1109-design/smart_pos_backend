<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\BackOfficeAuthorizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends BackOfficeController
{
    public function __invoke(Request $request, BackOfficeAuthorizer $authorizer): Response
    {
        $session = session('backoffice');
        $currency = $session['currency_code'] ?? 'USD';
        $tenantId = $this->tenantId();
        $locationIds = $authorizer->currentLocationScope();

        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to', 'Y-m-d') ?? now()->toDateString();

        $fromStart = Carbon::parse($from)->startOfDay();
        $toEnd = Carbon::parse($to)->endOfDay();

        // ── Sales summary ─────────────────────────────────────────────
        $summary = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(total), 0) as gross_revenue, COALESCE(SUM(discount_total), 0) as total_discounts, COALESCE(SUM(tax_total), 0) as total_tax, COALESCE(SUM(subtotal), 0) as net_sales')
            ->first();

        // ── Cost of Sales / Gross Profit ──────────────────────────────
        // Same derivation SalePostingService uses to post the real COGS
        // journal line for each sale — the weighted-average cost snapshot
        // captured on the stock_movements row at the moment of sale, not a
        // live/current cost that would drift as products are repriced.
        $totalCost = (float) StockMovement::whereIn('reference_id', function ($q) use ($tenantId, $fromStart, $toEnd, $locationIds) {
            $q->select('id')->from('transactions')
                ->where('business_id', $tenantId)
                ->where('status', 'completed')
                ->whereBetween('created_at', [$fromStart, $toEnd])
                ->when($locationIds, fn ($q2) => $q2->whereIn('location_id', $locationIds));
        })
            ->where('type', 'sale')
            ->get()
            ->sum(fn ($m) => abs((float) $m->quantity_change) * (float) ($m->running_avg_cost ?? 0));

        $summary->total_cost = round($totalCost, 2);
        $summary->gross_profit = round((float) $summary->net_sales - (float) $summary->total_discounts - $totalCost, 2);

        // ── Returns & voids ────────────────────────────────────────────
        // Excluded from the "completed" summary above by design (a voided
        // or refunded sale isn't a completed one), but the spec requires
        // them as their own visible line rather than silently dropped.
        //
        // A refund writes TWO rows: the original sale (label flipped to
        // 'refunded'/'partial_refund', its ORIGINAL positive total left
        // untouched) plus a brand-new compensating transaction carrying the
        // actual signed refund amount (always status 'refunded', total < 0
        // regardless of full/partial). Summing every 'refunded'/
        // 'partial_refund' row's absolute total would double-count the
        // refunded amount — only the negative-total compensating row is the
        // real money movement, so that's what's counted here.
        $returnsAndVoids = Transaction::where('business_id', $tenantId)
            ->whereIn('status', ['voided', 'refunded', 'partial_refund'])
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total ELSE 0 END), 0) as voids_total,
                COUNT(CASE WHEN status = 'voided' THEN 1 END) as voids_count,
                COALESCE(SUM(CASE WHEN status IN ('refunded', 'partial_refund') AND total < 0 THEN -total ELSE 0 END), 0) as returns_total,
                COUNT(CASE WHEN status IN ('refunded', 'partial_refund') AND total < 0 THEN 1 END) as returns_count
            ")
            ->first();

        $summary->voids_total = round((float) $returnsAndVoids->voids_total, 2);
        $summary->voids_count = (int) $returnsAndVoids->voids_count;
        $summary->returns_total = round((float) $returnsAndVoids->returns_total, 2);
        $summary->returns_count = (int) $returnsAndVoids->returns_count;

        // ── Daily breakdown ───────────────────────────────────────────
        $dailyBreakdown = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as transactions, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(discount_total), 0) as discounts')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        // ── Payment method breakdown ──────────────────────────────────
        $paymentMethods = Payment::join('transactions', 'payments.transaction_id', '=', 'transactions.id')
            ->where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->selectRaw('payments.method, COUNT(*) as count, COALESCE(SUM(payments.base_equivalent), 0) as total')
            ->groupBy('payments.method')
            ->orderByDesc('total')
            ->get();

        // ── Top products ──────────────────────────────────────────────
        $topProducts = TransactionItem::join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->selectRaw('products.name, products.sku, SUM(transaction_items.quantity) as units_sold, COALESCE(SUM(transaction_items.line_total), 0) as revenue, COALESCE(AVG(transaction_items.unit_price), 0) as avg_price')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        // ── Fast movers (ranked by sales velocity, not revenue) ────────
        // A high-revenue product isn't necessarily a fast mover (a single
        // big-ticket item skews "Top Products by Revenue"); this ranks by
        // units sold per day over the selected range instead.
        $rangeDays = max(1, $fromStart->diffInDays($toEnd) + 1);
        $fastMovers = TransactionItem::join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->selectRaw('products.name, products.sku, SUM(transaction_items.quantity) as units_sold, COALESCE(SUM(transaction_items.line_total), 0) as revenue')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('units_sold')
            ->limit(20)
            ->get()
            ->map(function ($row) use ($rangeDays) {
                $row->velocity = round((float) $row->units_sold / $rangeDays, 2);

                return $row;
            });

        // ── Sales by cashier ──────────────────────────────────────────
        $byCashier = Transaction::where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
            ->whereNotNull('transactions.user_id')
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->selectRaw('users.name, COUNT(transactions.id) as transactions, COALESCE(SUM(transactions.total), 0) as revenue')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('revenue')
            ->get();

        return Inertia::render('BackOffice/Reports', [
            'summary' => $summary,
            'daily_breakdown' => $dailyBreakdown,
            'payment_methods' => $paymentMethods,
            'top_products' => $topProducts,
            'fast_movers' => $fastMovers,
            'by_cashier' => $byCashier,
            'currency' => $currency,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }
}
