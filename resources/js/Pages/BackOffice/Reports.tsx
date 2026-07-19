import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import { ColumnTrendChart, LineTrendChart, RankedBarChart, TrendPoint } from '@/Components/Charts';

interface Summary {
    total_transactions: number;
    gross_revenue: number;
    total_discounts: number;
    total_tax: number;
    net_sales: number;
}

interface DailyRow {
    date: string;
    transactions: number;
    revenue: number;
    discounts: number;
}

interface PaymentMethod {
    method: string;
    count: number;
    total: number;
}

interface ProductRow {
    name: string;
    sku: string | null;
    units_sold: number;
    revenue: number;
    avg_price: number;
}

interface CashierRow {
    name: string;
    transactions: number;
    revenue: number;
}

interface Props {
    summary: Summary;
    daily_breakdown: DailyRow[];
    payment_methods: PaymentMethod[];
    top_products: ProductRow[];
    by_cashier: CashierRow[];
    currency: string;
    filters: { from: string; to: string };
}

function fmt(amount: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(amount);
}

function methodLabel(method: string): string {
    const map: Record<string, string> = { cash: 'Cash', card: 'Card', mobile: 'Mobile Money', credit: 'Credit' };
    return map[method] ?? method;
}

type Granularity = 'daily' | 'weekly' | 'monthly';

/** Group the daily rows into trend points at the chosen granularity. */
function toTrendPoints(rows: DailyRow[], granularity: Granularity): TrendPoint[] {
    if (granularity === 'daily') {
        return rows.map((row) => ({
            label: new Date(row.date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }),
            tooltip: new Date(row.date).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }),
            value: Number(row.revenue),
            secondary: `${row.transactions} transaction${row.transactions === 1 ? '' : 's'}`,
        }));
    }

    const buckets = new Map<string, { start: Date; revenue: number; transactions: number }>();
    for (const row of rows) {
        const d = new Date(row.date);
        let key: string;
        let start: Date;
        if (granularity === 'weekly') {
            // Bucket by ISO week start (Monday).
            const monday = new Date(d);
            monday.setDate(d.getDate() - ((d.getDay() + 6) % 7));
            key = monday.toISOString().slice(0, 10);
            start = monday;
        } else {
            key = `${d.getFullYear()}-${d.getMonth()}`;
            start = new Date(d.getFullYear(), d.getMonth(), 1);
        }
        const bucket = buckets.get(key) ?? { start, revenue: 0, transactions: 0 };
        bucket.revenue += Number(row.revenue);
        bucket.transactions += Number(row.transactions);
        buckets.set(key, bucket);
    }

    return [...buckets.values()]
        .sort((a, b) => a.start.getTime() - b.start.getTime())
        .map((bucket) => ({
            label: granularity === 'weekly'
                ? bucket.start.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
                : bucket.start.toLocaleDateString(undefined, { month: 'short', year: '2-digit' }),
            tooltip: granularity === 'weekly'
                ? `Week of ${bucket.start.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}`
                : bucket.start.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
            value: bucket.revenue,
            secondary: `${bucket.transactions} transaction${bucket.transactions === 1 ? '' : 's'}`,
        }));
}

export default function BackOfficeReports({ summary, daily_breakdown, payment_methods, top_products, by_cashier, currency, filters }: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [chartType, setChartType] = useState<'columns' | 'line'>('columns');
    const [granularity, setGranularity] = useState<Granularity>('daily');

    const applyFilter = () => {
        router.get('/office/reports', { from, to }, { preserveState: true });
    };

    const avgOrder = summary.total_transactions > 0
        ? Number(summary.gross_revenue) / summary.total_transactions
        : 0;

    return (
        <BackOfficeLayout>
            <Head title="Reports" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Sales Report</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        {new Date(filters.from).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                        {' — '}
                        {new Date(filters.to).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 flex-1 min-w-0"
                    />
                    <span className="text-slate-400 text-sm flex-shrink-0">to</span>
                    <input
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 flex-1 min-w-0"
                    />
                    <button onClick={applyFilter} className="btn-primary py-2 flex-shrink-0">Apply</button>
                </div>
            </div>

            {/* Summary cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 mb-8">
                {[
                    { label: 'Gross Revenue',    value: fmt(Number(summary.gross_revenue), currency)  },
                    { label: 'Net Sales',        value: fmt(Number(summary.net_sales), currency)      },
                    { label: 'Total Discounts',  value: fmt(Number(summary.total_discounts), currency) },
                    { label: 'Tax Collected',    value: fmt(Number(summary.total_tax), currency)      },
                    { label: 'Avg Order Value',  value: fmt(avgOrder, currency)                        },
                ].map(({ label, value }) => (
                    <div key={label} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                        <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{label}</p>
                        <p className="text-xl font-bold text-slate-900 mt-1.5">{value}</p>
                    </div>
                ))}
            </div>
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm px-5 py-3 mb-8 inline-flex items-center gap-2">
                <span className="text-2xl font-bold text-slate-900">{summary.total_transactions}</span>
                <span className="text-sm text-slate-500">total transactions</span>
            </div>

            {/* Revenue trend */}
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 mb-6">
                <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <p className="text-sm font-semibold text-slate-700">
                        Revenue {granularity === 'daily' ? 'by Day' : granularity === 'weekly' ? 'by Week' : 'by Month'}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="flex rounded-lg border border-slate-200 overflow-hidden">
                            {(['daily', 'weekly', 'monthly'] as const).map((g) => (
                                <button
                                    key={g}
                                    onClick={() => setGranularity(g)}
                                    className={`text-xs px-3 py-1.5 font-medium transition ${
                                        granularity === g ? 'bg-emerald-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50'
                                    }`}
                                >
                                    {g === 'daily' ? 'Daily' : g === 'weekly' ? 'Weekly' : 'Monthly'}
                                </button>
                            ))}
                        </div>
                        <div className="flex rounded-lg border border-slate-200 overflow-hidden">
                            {(['columns', 'line'] as const).map((t) => (
                                <button
                                    key={t}
                                    onClick={() => setChartType(t)}
                                    className={`text-xs px-3 py-1.5 font-medium transition ${
                                        chartType === t ? 'bg-emerald-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50'
                                    }`}
                                >
                                    {t === 'columns' ? 'Columns' : 'Line'}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
                {chartType === 'columns' ? (
                    <ColumnTrendChart
                        points={toTrendPoints(daily_breakdown, granularity)}
                        valueFormatter={(v) => fmt(v, currency)}
                    />
                ) : (
                    <LineTrendChart
                        points={toTrendPoints(daily_breakdown, granularity)}
                        valueFormatter={(v) => fmt(v, currency)}
                    />
                )}
            </div>

            {/* Ranked charts: what's selling, who's selling */}
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2 mb-6">
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <p className="text-sm font-semibold text-slate-700 mb-4">Top Products by Revenue</p>
                    <RankedBarChart
                        bars={top_products.slice(0, 8).map((p) => ({
                            label: p.name,
                            value: Number(p.revenue),
                            secondary: `${Number(p.units_sold).toFixed(0)} units`,
                        }))}
                        valueFormatter={(v) => fmt(v, currency)}
                    />
                </div>
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <p className="text-sm font-semibold text-slate-700 mb-4">Sales by Cashier</p>
                    <RankedBarChart
                        bars={by_cashier.map((c) => ({
                            label: c.name,
                            value: Number(c.revenue),
                            secondary: `${c.transactions} txns`,
                        }))}
                        valueFormatter={(v) => fmt(v, currency)}
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2 mb-6">
                {/* Daily breakdown */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-6 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-700">Daily Breakdown</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[480px]">
                            <thead>
                                <tr className="bg-slate-50">
                                    <th className="table-th">Date</th>
                                    <th className="table-th text-right">Transactions</th>
                                    <th className="table-th text-right">Revenue</th>
                                    <th className="table-th text-right">Discounts</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {daily_breakdown.map((row) => (
                                    <tr key={row.date} className="hover:bg-slate-50/60">
                                        <td className="table-td text-slate-600">
                                            {new Date(row.date).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}
                                        </td>
                                        <td className="table-td text-right text-slate-700">{row.transactions}</td>
                                        <td className="table-td text-right font-medium text-slate-900">{fmt(Number(row.revenue), currency)}</td>
                                        <td className="table-td text-right text-red-500">{fmt(Number(row.discounts), currency)}</td>
                                    </tr>
                                ))}
                                {daily_breakdown.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-6 py-8 text-center text-sm text-slate-400">No data for selected range.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Payment methods + Cashier performance */}
                <div className="space-y-6">
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                        <p className="text-sm font-semibold text-slate-700 mb-4">Payment Methods</p>
                        {payment_methods.length === 0 ? (
                            <p className="text-sm text-slate-400">No data.</p>
                        ) : (
                            <div className="space-y-3">
                                {payment_methods.map((p) => {
                                    const totalAll = payment_methods.reduce((s, x) => s + Number(x.total), 0);
                                    const pct = totalAll > 0 ? Math.round((Number(p.total) / totalAll) * 100) : 0;
                                    return (
                                        <div key={p.method} className="flex items-center gap-3">
                                            <div className="w-24 flex-shrink-0">
                                                <p className="text-sm font-medium text-slate-700">{methodLabel(p.method)}</p>
                                                <p className="text-xs text-slate-400">{p.count} txns</p>
                                            </div>
                                            <div className="flex-1">
                                                <div className="w-full bg-slate-100 rounded-full h-2">
                                                    <div className="bg-emerald-500 h-2 rounded-full" style={{ width: `${pct}%` }} />
                                                </div>
                                            </div>
                                            <div className="text-right flex-shrink-0">
                                                <p className="text-sm font-semibold text-slate-800">{fmt(Number(p.total), currency)}</p>
                                                <p className="text-xs text-slate-400">{pct}%</p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                        <p className="text-sm font-semibold text-slate-700 mb-4">Sales by Cashier</p>
                        {by_cashier.length === 0 ? (
                            <p className="text-sm text-slate-400">No data.</p>
                        ) : (
                            <div className="space-y-3">
                                {by_cashier.map((c) => (
                                    <div key={c.name} className="flex items-center justify-between">
                                        <div>
                                            <p className="text-sm font-medium text-slate-800">{c.name}</p>
                                            <p className="text-xs text-slate-400">{c.transactions} transactions</p>
                                        </div>
                                        <span className="text-sm font-semibold text-emerald-700">{fmt(Number(c.revenue), currency)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Top products table */}
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-100">
                    <p className="text-sm font-semibold text-slate-700">Product Performance</p>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[480px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">#</th>
                                <th className="table-th">Product</th>
                                <th className="table-th">SKU</th>
                                <th className="table-th text-right">Units Sold</th>
                                <th className="table-th text-right">Avg Price</th>
                                <th className="table-th text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {top_products.map((p, i) => (
                                <tr key={p.name} className="hover:bg-slate-50/60">
                                    <td className="table-td text-slate-400 font-mono text-xs">{i + 1}</td>
                                    <td className="table-td font-medium text-slate-800">{p.name}</td>
                                    <td className="table-td text-slate-400 font-mono text-xs">{p.sku ?? '—'}</td>
                                    <td className="table-td text-right text-slate-700">{p.units_sold}</td>
                                    <td className="table-td text-right text-slate-500">{fmt(Number(p.avg_price), currency)}</td>
                                    <td className="table-td text-right font-semibold text-slate-900">{fmt(Number(p.revenue), currency)}</td>
                                </tr>
                            ))}
                            {top_products.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-6 py-8 text-center text-sm text-slate-400">No product sales in this period.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
