import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface OpenShiftRow {
    id: string;
    opened_at: string;
    opening_float: string;
    total_sales: string | null;
    cash_sales: string | null;
    transaction_count: number | null;
    cashier_name: string | null;
    location_name: string | null;
}

interface ShiftRow {
    id: string;
    opened_at: string;
    closed_at: string | null;
    status: string;
    opening_float: string;
    expected_cash: string | null;
    counted_cash: string | null;
    variance: string | null;
    total_sales: string | null;
    cash_sales: string | null;
    card_sales: string | null;
    mobile_money_sales: string | null;
    credit_sales: string | null;
    total_refunds: string | null;
    total_discounts: string | null;
    transaction_count: number | null;
    notes: string | null;
    cashier_name: string | null;
    location_name: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface Summary {
    total_shifts: number;
    open_count: number;
    closed_count: number;
    total_sales: string;
    total_variance: string;
    total_shortage: string;
}

interface LocationBreakdownRow {
    location_id: string | null;
    location_name: string;
    total_shifts: number;
    open_count: number;
    total_sales: string;
    total_variance: string;
}

interface LocationOption {
    id: string;
    name: string;
}

interface Props {
    open_shifts: OpenShiftRow[];
    shifts: Paginated<ShiftRow>;
    summary: Summary;
    by_location: LocationBreakdownRow[];
    locations: LocationOption[];
    currency: string;
    filters: { from: string; to: string; status: string; location: string };
}

function fmt(amount: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(amount);
}

function num(value: string | number | null | undefined): number {
    return value === null || value === undefined ? 0 : Number(value);
}

function timeAgo(iso: string): string {
    const ms = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(ms / 60000);
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ${mins % 60}m ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

function duration(openedAt: string, closedAt: string | null): string {
    const end = closedAt ? new Date(closedAt).getTime() : Date.now();
    const mins = Math.max(0, Math.floor((end - new Date(openedAt).getTime()) / 60000));
    const hours = Math.floor(mins / 60);
    return hours > 0 ? `${hours}h ${mins % 60}m` : `${mins}m`;
}

function VarianceLabel({ value, currency }: { value: string | number | null; currency: string }) {
    const v = num(value);
    if (value === null || value === undefined) return <span className="text-slate-300">—</span>;
    if (v === 0) return <span className="text-slate-500">{fmt(0, currency)}</span>;
    const tone = v < 0 ? 'text-red-600' : 'text-emerald-600';
    const sign = v > 0 ? '+' : '';
    return <span className={`font-semibold ${tone}`}>{sign}{fmt(v, currency)}</span>;
}

function StatusBadge({ status }: { status: string }) {
    if (status === 'open') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                Open
            </span>
        );
    }
    return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">Closed</span>;
}

const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
];

export default function BackOfficeShifts({ open_shifts, shifts, summary, by_location, locations, currency, filters }: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const applyFilter = (overrides?: { status?: string; location?: string }) => {
        router.get('/office/shifts', {
            from,
            to,
            status: overrides?.status ?? filters.status,
            location: overrides?.location ?? filters.location,
        }, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Shifts" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Shifts</h1>
                    <p className="text-sm text-slate-500 mt-1">Who's on till, cash positions, and shift history — no need to be at a device.</p>
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
                    <select
                        value={filters.location}
                        onChange={(e) => applyFilter({ location: e.target.value })}
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 flex-shrink-0"
                    >
                        <option value="all">All locations</option>
                        {locations.map((l) => (
                            <option key={l.id} value={l.id}>{l.name}</option>
                        ))}
                    </select>
                    <button onClick={() => applyFilter()} className="btn-primary py-2 flex-shrink-0">Apply</button>
                </div>
            </div>

            {/* Currently open shifts — the live view */}
            <div className="mb-8">
                <div className="flex items-center gap-2 mb-3">
                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
                    <h2 className="text-sm font-semibold text-slate-700">On till right now ({open_shifts.length})</h2>
                </div>
                {open_shifts.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 text-center text-sm text-slate-400">
                        No shift is currently open. Every till is closed out.
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {open_shifts.map((s) => (
                            <div key={s.id} className="bg-white rounded-2xl border border-emerald-100 shadow-sm p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-slate-900 truncate">{s.cashier_name ?? 'Unknown cashier'}</p>
                                        <p className="text-xs text-slate-400 mt-0.5 truncate">{s.location_name ?? 'Main location'}</p>
                                    </div>
                                    <StatusBadge status="open" />
                                </div>
                                <div className="grid grid-cols-2 gap-x-3 gap-y-2 mt-3 text-xs">
                                    <div>
                                        <p className="text-slate-400">Opened</p>
                                        <p className="font-medium text-slate-700">{timeAgo(s.opened_at)}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Opening float</p>
                                        <p className="font-medium text-slate-700">{fmt(num(s.opening_float), currency)}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Sales so far</p>
                                        <p className="font-semibold text-emerald-600">{fmt(num(s.total_sales), currency)}</p>
                                    </div>
                                    <div>
                                        <p className="text-slate-400">Transactions</p>
                                        <p className="font-medium text-slate-700">{s.transaction_count ?? 0}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Summary cards for the selected range */}
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
                {[
                    { label: 'Shifts in range', value: summary.total_shifts, tone: 'text-slate-900', money: false },
                    { label: 'Currently open', value: summary.open_count, tone: 'text-emerald-600', money: false },
                    { label: 'Total sales', value: num(summary.total_sales), tone: 'text-slate-900', money: true },
                    { label: 'Cash variance', value: num(summary.total_variance), tone: num(summary.total_variance) < 0 ? 'text-red-600' : 'text-emerald-600', money: true },
                ].map(({ label, value, tone, money }) => (
                    <div key={label} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                        <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{label}</p>
                        <p className={`text-xl font-bold mt-1.5 ${tone}`}>{money ? fmt(Number(value), currency) : Number(value)}</p>
                    </div>
                ))}
            </div>

            {/* Breakdown by location — how each shop is doing, independent of the location filter */}
            {by_location.length > 1 && (
                <div className="mb-6">
                    <h2 className="text-sm font-semibold text-slate-700 mb-3">By location</h2>
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm min-w-[560px]">
                                <thead>
                                    <tr className="bg-slate-50">
                                        <th className="table-th">Location</th>
                                        <th className="table-th text-right">Shifts</th>
                                        <th className="table-th text-right">Open now</th>
                                        <th className="table-th text-right">Sales</th>
                                        <th className="table-th text-right">Variance</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {by_location.map((row) => (
                                        <tr key={row.location_id ?? 'unassigned'} className="hover:bg-slate-50/60">
                                            <td className="table-td font-medium text-slate-900">{row.location_name}</td>
                                            <td className="table-td text-right text-slate-600">{row.total_shifts}</td>
                                            <td className="table-td text-right text-slate-600">{row.open_count > 0 ? row.open_count : '—'}</td>
                                            <td className="table-td text-right font-semibold text-slate-900">{fmt(num(row.total_sales), currency)}</td>
                                            <td className="table-td text-right"><VarianceLabel value={row.total_variance} currency={currency} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}

            {/* Status filter chips */}
            <div className="flex flex-wrap gap-2 mb-4">
                {STATUS_FILTERS.map(({ value, label }) => (
                    <button
                        key={value}
                        onClick={() => applyFilter({ status: value })}
                        className={`text-sm px-3 py-1.5 rounded-full border transition ${
                            filters.status === value
                                ? 'bg-emerald-600 border-emerald-600 text-white'
                                : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-300'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                {/* Mobile: card list */}
                <div className="md:hidden divide-y divide-slate-50">
                    {shifts.data.map((s) => (
                        <div key={s.id} className="p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-slate-900 truncate">{s.cashier_name ?? 'Unknown cashier'}</p>
                                    <p className="text-xs text-slate-400 mt-0.5">
                                        {new Date(s.opened_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                        {s.location_name ? ` · ${s.location_name}` : ''}
                                    </p>
                                </div>
                                <span className="text-base font-bold text-slate-900 flex-shrink-0">{fmt(num(s.total_sales), currency)}</span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2 mt-3">
                                <StatusBadge status={s.status} />
                                <span className="text-xs text-slate-500">{duration(s.opened_at, s.closed_at)}</span>
                                <span className="text-xs text-slate-500">{s.transaction_count ?? 0} sales</span>
                                {s.status === 'closed' && (
                                    <span className="ml-auto text-xs">
                                        Var: <VarianceLabel value={s.variance} currency={currency} />
                                    </span>
                                )}
                            </div>
                        </div>
                    ))}
                    {shifts.data.length === 0 && (
                        <p className="px-4 py-10 text-center text-sm text-slate-400">
                            No shifts match this range and filter.
                        </p>
                    )}
                </div>

                {/* Desktop: table */}
                <div className="hidden md:block overflow-x-auto">
                    <table className="w-full text-sm min-w-[900px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Cashier</th>
                                <th className="table-th">Location</th>
                                <th className="table-th">Opened</th>
                                <th className="table-th">Duration</th>
                                <th className="table-th text-right">Opening float</th>
                                <th className="table-th text-right">Sales</th>
                                <th className="table-th text-right">Txns</th>
                                <th className="table-th text-right">Counted cash</th>
                                <th className="table-th text-right">Variance</th>
                                <th className="table-th">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {shifts.data.map((s) => (
                                <tr key={s.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">{s.cashier_name ?? '—'}</td>
                                    <td className="table-td text-slate-600">{s.location_name ?? '—'}</td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">
                                        {new Date(s.opened_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                    </td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">{duration(s.opened_at, s.closed_at)}</td>
                                    <td className="table-td text-right text-slate-600">{fmt(num(s.opening_float), currency)}</td>
                                    <td className="table-td text-right font-semibold text-slate-900">{fmt(num(s.total_sales), currency)}</td>
                                    <td className="table-td text-right text-slate-600">{s.transaction_count ?? 0}</td>
                                    <td className="table-td text-right text-slate-600">
                                        {s.counted_cash !== null ? fmt(num(s.counted_cash), currency) : <span className="text-slate-300">—</span>}
                                    </td>
                                    <td className="table-td text-right"><VarianceLabel value={s.variance} currency={currency} /></td>
                                    <td className="table-td"><StatusBadge status={s.status} /></td>
                                </tr>
                            ))}
                            {shifts.data.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="px-6 py-10 text-center text-sm text-slate-400">
                                        No shifts match this range and filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {shifts.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {shifts.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveState
                                    className={`text-sm px-3 py-1.5 rounded-lg ${
                                        link.active ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={i}
                                    className="text-sm px-3 py-1.5 text-slate-300"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )
                        )}
                    </div>
                )}
            </div>
        </BackOfficeLayout>
    );
}
