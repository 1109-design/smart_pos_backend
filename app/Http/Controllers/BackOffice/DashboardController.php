<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\BackOfficeAuthorizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BackOfficeAuthorizer $authorizer): Response
    {
        $session = session('backoffice');
        $currency = $session['currency_code'] ?? 'USD';
        $tenantId = $this->tenantId();

        // A user scoped to specific locations (see location_user) only sees
        // this business's data for their own branch(es) — null means
        // unrestricted, today's behavior for everyone until scoped.
        $locationIds = $authorizer->currentLocationScope();

        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        // ── Today's stats ─────────────────────────────────────────────
        $todayTransactions = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $today)
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as revenue')
            ->first();

        // ── This week ─────────────────────────────────────────────────
        $weekRevenue = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $weekStart)
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->sum('total');

        // ── This month ────────────────────────────────────────────────
        $monthRevenue = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $monthStart)
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->sum('total');

        $monthCount = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $monthStart)
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->count();

        // ── Customer count ────────────────────────────────────────────
        // Customers aren't owned by a single location in this schema, so
        // this stays business-wide even for a location-scoped user.
        $customerCount = Customer::where('business_id', $tenantId)->count();

        // ── Low stock products ────────────────────────────────────────
        // Same reason: stock_quantity is a cross-location total today (see
        // [[smartpos-project-map]]), not something a single location owns.
        $lowStockCount = Product::where('business_id', $tenantId)
            ->where('track_stock', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->where('is_active', true)
            ->count();

        // ── Payment method breakdown (this month) ─────────────────────
        $paymentBreakdown = Payment::join('transactions', 'payments.transaction_id', '=', 'transactions.id')
            ->where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->where('transactions.created_at', '>=', $monthStart)
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->selectRaw('payments.method, COUNT(*) as count, COALESCE(SUM(payments.base_equivalent), 0) as total')
            ->groupBy('payments.method')
            ->orderByDesc('total')
            ->get();

        // ── Top 5 products this month ─────────────────────────────────
        $topProducts = TransactionItem::join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products', 'transaction_items.product_id', '=', 'products.id')
            ->where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->where('transactions.created_at', '>=', $monthStart)
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->selectRaw('products.name, SUM(transaction_items.quantity) as units_sold, COALESCE(SUM(transaction_items.line_total), 0) as revenue')
            ->groupBy('transaction_items.id', 'products.id', 'products.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        // ── Revenue last 7 days ───────────────────────────────────────
        $last7Days = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->selectRaw('DATE(created_at) as date, COALESCE(SUM(total), 0) as revenue, COUNT(*) as count')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $chartData = collect();
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $label = now()->subDays($i)->format('D');
            $day = $last7Days->get($date);
            $chartData->push([
                'label' => $label,
                'date' => $date,
                'revenue' => $day ? (float) $day->revenue : 0,
                'count' => $day ? (int) $day->count : 0,
            ]);
        }

        // ── Recent transactions ───────────────────────────────────────
        $recentTransactions = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'sale_number', 'total', 'base_currency', 'created_at']);

        // ── Active shift ──────────────────────────────────────────────
        $activeShift = Shift::where('business_id', $tenantId)
            ->where('status', 'open')
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->orderByDesc('opened_at')
            ->first(['id', 'opened_at', 'opening_float', 'transaction_count']);

        return Inertia::render('BackOffice/Dashboard', [
            'stats' => [
                'today_revenue' => (float) $todayTransactions->revenue,
                'today_count' => (int) $todayTransactions->count,
                'week_revenue' => (float) $weekRevenue,
                'month_revenue' => (float) $monthRevenue,
                'month_count' => (int) $monthCount,
                'customer_count' => $customerCount,
                'low_stock_count' => $lowStockCount,
            ],
            'chart_data' => $chartData->values(),
            'payment_breakdown' => $paymentBreakdown,
            'top_products' => $topProducts,
            'recent_transactions' => $recentTransactions,
            'active_shift' => $activeShift,
            'currency' => $currency,
        ]);
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
