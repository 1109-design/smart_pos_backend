<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $session = session('backoffice');
        $currency = $session['currency_code'] ?? 'USD';
        $tenantId = $this->tenantId();

        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to', 'Y-m-d') ?? now()->toDateString();

        $fromStart = Carbon::parse($from)->startOfDay();
        $toEnd = Carbon::parse($to)->endOfDay();

        // ── Sales summary ─────────────────────────────────────────────
        $summary = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->selectRaw('COUNT(*) as total_transactions, COALESCE(SUM(total), 0) as gross_revenue, COALESCE(SUM(discount_total), 0) as total_discounts, COALESCE(SUM(tax_total), 0) as total_tax, COALESCE(SUM(subtotal), 0) as net_sales')
            ->first();

        // ── Daily breakdown ───────────────────────────────────────────
        $dailyBreakdown = Transaction::where('business_id', $tenantId)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as transactions, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(discount_total), 0) as discounts')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('date')
            ->get();

        // ── Payment method breakdown ──────────────────────────────────
        $paymentMethods = Payment::join('transactions', 'payments.transaction_id', '=', 'transactions.id')
            ->where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
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
            ->selectRaw('products.name, products.sku, SUM(transaction_items.quantity) as units_sold, COALESCE(SUM(transaction_items.line_total), 0) as revenue, COALESCE(AVG(transaction_items.unit_price), 0) as avg_price')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();

        // ── Sales by cashier ──────────────────────────────────────────
        $byCashier = Transaction::where('transactions.business_id', $tenantId)
            ->where('transactions.status', 'completed')
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
            ->whereNotNull('transactions.user_id')
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
            'by_cashier' => $byCashier,
            'currency' => $currency,
            'filters' => ['from' => $from, 'to' => $to],
        ]);
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
