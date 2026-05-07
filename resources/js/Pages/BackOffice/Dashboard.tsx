import React from 'react';
import { Head, Link } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface Stats {
    today_revenue: number;
    today_count: number;
    week_revenue: number;
    month_revenue: number;
    month_count: number;
    customer_count: number;
    low_stock_count: number;
}

interface ChartPoint {
    label: string;
    date: string;
    revenue: number;
    count: number;
}

interface PaymentMethod {
    method: string;
    count: number;
    total: number;
}

interface TopProduct {
    name: string;
    units_sold: number;
    revenue: number;
}

interface RecentTransaction {
    id: string;
    sale_number: string | null;
    total: number;
    base_currency: string;
    created_at: string;
}

interface Shift {
    id: string;
    opened_at: string;
    opening_float: number;
    transaction_count: number;
}

interface Props {
    stats: Stats;
    chart_data: ChartPoint[];
    payment_breakdown: PaymentMethod[];
    top_products: TopProduct[];
    recent_transactions: RecentTransaction[];
    active_shift: Shift | null;
    currency: string;
}

function fmt(amount: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(amount);
}

function StatCard({ label, value, sub, accent }: { label: string; value: string; sub?: string; accent?: string }) {
    return (
        <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{label}</p>
            <p className={`text-2xl font-bold mt-1.5 ${accent ?? 'text-slate-900'}`}>{value}</p>
            {sub && <p className="text-xs text-slate-400 mt-1">{sub}</p>}
        </div>
    );
}

function methodLabel(method: string): string {
    const map: Record<string, string> = { cash: 'Cash', card: 'Card', mobile: 'Mobile Money', credit: 'Credit' };
    return map[method] ?? method;
}

export default function BackOfficeDashboard({
    stats, chart_data, payment_breakdown, top_products, recent_transactions, active_shift, currency,
}: Props) {
    const maxRevenue = Math.max(...chart_data.map((d) => d.revenue), 1);

    return (
        <BackOfficeLayout>
            <Head title="Dashboard" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Dashboard</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        {new Date().toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                    </p>
                </div>
                <Link href="/office/reports" className="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                        <path fillRule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-4.12-4.122A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0-6a.75.75 0 0 0 0 1.5h3a.75.75 0 0 0 0-1.5h-3Z" clipRule="evenodd" />
                    </svg>
                    Generate Report
                </Link>
            </div>

            {/* Active shift banner */}
            {active_shift && (
                <div className="mb-6 bg-emerald-50 border border-emerald-200 rounded-2xl px-5 py-4 flex items-center gap-4">
                    <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-emerald-800">Shift is open</p>
                        <p className="text-xs text-emerald-600 mt-0.5">
                            Opened {new Date(active_shift.opened_at).toLocaleTimeString()} · {active_shift.transaction_count} transactions · Float {fmt(active_shift.opening_float, currency)}
                        </p>
                    </div>
                </div>
            )}

            {/* Stat cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                <StatCard label="Today's Sales" value={fmt(stats.today_revenue, currency)} sub={`${stats.today_count} transactions`} accent="text-emerald-700" />
                <StatCard label="This Week" value={fmt(stats.week_revenue, currency)} />
                <StatCard label="This Month" value={fmt(stats.month_revenue, currency)} sub={`${stats.month_count} transactions`} />
                <StatCard label="Customers" value={stats.customer_count.toString()} />
            </div>

            {stats.low_stock_count > 0 && (
                <div className="mb-6 bg-amber-50 border border-amber-200 rounded-2xl px-5 py-3 flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4 text-amber-500 flex-shrink-0">
                        <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clipRule="evenodd" />
                    </svg>
                    <p className="text-sm font-medium text-amber-800">
                        {stats.low_stock_count} {stats.low_stock_count === 1 ? 'product is' : 'products are'} low on stock
                    </p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                {/* Revenue chart */}
                <div className="xl:col-span-2 bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <p className="text-sm font-semibold text-slate-700 mb-4">Revenue — Last 7 Days</p>
                    <div className="flex items-end gap-2 h-36">
                        {chart_data.map((d) => {
                            const height = maxRevenue > 0 ? Math.max((d.revenue / maxRevenue) * 100, d.revenue > 0 ? 4 : 0) : 0;
                            return (
                                <div key={d.date} className="flex-1 flex flex-col items-center gap-1.5 group">
                                    <div className="relative w-full flex justify-center">
                                        {d.revenue > 0 && (
                                            <div className="absolute -top-6 hidden group-hover:block bg-slate-800 text-white text-xs rounded px-1.5 py-0.5 whitespace-nowrap z-10">
                                                {fmt(d.revenue, currency)}
                                            </div>
                                        )}
                                        <div
                                            className="w-full rounded-t-lg bg-emerald-500 transition-all"
                                            style={{ height: `${height}%`, minHeight: d.revenue > 0 ? '4px' : '0' }}
                                        />
                                    </div>
                                    <span className="text-xs text-slate-400">{d.label}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Payment methods */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <p className="text-sm font-semibold text-slate-700 mb-4">Payment Methods <span className="text-slate-400 font-normal">(month)</span></p>
                    {payment_breakdown.length === 0 ? (
                        <p className="text-sm text-slate-400">No transactions yet.</p>
                    ) : (
                        <div className="space-y-3">
                            {payment_breakdown.map((p) => {
                                const totalAll = payment_breakdown.reduce((s, x) => s + Number(x.total), 0);
                                const pct = totalAll > 0 ? Math.round((Number(p.total) / totalAll) * 100) : 0;
                                return (
                                    <div key={p.method}>
                                        <div className="flex items-center justify-between text-sm mb-1">
                                            <span className="font-medium text-slate-700">{methodLabel(p.method)}</span>
                                            <span className="text-slate-500">{fmt(Number(p.total), currency)}</span>
                                        </div>
                                        <div className="w-full bg-slate-100 rounded-full h-1.5">
                                            <div className="bg-emerald-500 h-1.5 rounded-full transition-all" style={{ width: `${pct}%` }} />
                                        </div>
                                        <p className="text-xs text-slate-400 mt-0.5">{p.count} transactions · {pct}%</p>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2 mt-6">
                {/* Top products */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <p className="text-sm font-semibold text-slate-700 mb-4">Top Products <span className="text-slate-400 font-normal">(month)</span></p>
                    {top_products.length === 0 ? (
                        <p className="text-sm text-slate-400">No sales data yet.</p>
                    ) : (
                        <div className="space-y-3">
                            {top_products.map((p, i) => (
                                <div key={p.name} className="flex items-center gap-3">
                                    <span className="w-5 text-xs font-bold text-slate-300 text-right flex-shrink-0">{i + 1}</span>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-slate-800 truncate">{p.name}</p>
                                        <p className="text-xs text-slate-400">{p.units_sold} units sold</p>
                                    </div>
                                    <span className="text-sm font-semibold text-emerald-700 flex-shrink-0">
                                        {fmt(Number(p.revenue), currency)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Recent transactions */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                    <p className="text-sm font-semibold text-slate-700 mb-4">Recent Transactions</p>
                    {recent_transactions.length === 0 ? (
                        <p className="text-sm text-slate-400">No transactions yet.</p>
                    ) : (
                        <div className="space-y-2">
                            {recent_transactions.map((t) => (
                                <div key={t.id} className="flex items-center justify-between py-2 border-b border-slate-50 last:border-0">
                                    <div>
                                        <p className="text-sm font-medium text-slate-800">
                                            {t.sale_number ?? `#${t.id.slice(-6).toUpperCase()}`}
                                        </p>
                                        <p className="text-xs text-slate-400">
                                            {new Date(t.created_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
                                        </p>
                                    </div>
                                    <span className="text-sm font-semibold text-slate-800">
                                        {fmt(Number(t.total), t.base_currency || currency)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
