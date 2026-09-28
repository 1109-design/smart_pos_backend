import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface LocationCol {
    id: string;
    name: string;
}

interface Cell {
    quantity: number;
    value: number;
}

interface Row {
    id: string;
    name: string;
    sku: string | null;
    category: string | null;
    cost_price: number;
    locations: Record<string, Cell>;
    total_quantity: number;
    total_value: number;
}

interface Totals {
    locations: Record<string, Cell>;
    quantity: number;
    value: number;
}

interface Category {
    id: string;
    name: string;
}

interface Filters {
    search: string | null;
    category_id: string | null;
    hide_zero: boolean;
}

interface Props {
    locations: LocationCol[];
    rows: Row[];
    totals: Totals;
    categories: Category[];
    currency: string;
    filters: Filters;
}

function fmtQty(n: number): string {
    // Whole numbers print clean; fractional units (e.g. weighed stock) keep precision.
    return Number.isInteger(n) ? String(n) : n.toFixed(2);
}

function fmtValue(n: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(n);
}

export default function StockInventoryByLocation({ locations, rows, totals, categories, currency, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [categoryId, setCategoryId] = useState(filters.category_id ?? '');
    const [hideZero, setHideZero] = useState(filters.hide_zero);

    const applyFilter = (overrides: Partial<{ search: string; category_id: string; hide_zero: boolean }> = {}) => {
        router.get('/office/reports/inventory-by-location', {
            search: overrides.search ?? search,
            category_id: overrides.category_id ?? categoryId,
            hide_zero: (overrides.hide_zero ?? hideZero) ? '1' : '',
        }, { preserveState: true });
    };

    const exportUrl = `/office/reports/inventory-by-location/export?search=${encodeURIComponent(search)}&category_id=${encodeURIComponent(categoryId)}&hide_zero=${hideZero ? '1' : ''}`;

    const asOfDate = new Date().toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });

    return (
        <BackOfficeLayout>
            <Head title="Stock Inventory by Location" />

            {/* Scoped print stylesheet: this report is a wide product x location
                matrix, so printing lands better in landscape with a compact
                type scale — no need to touch every other BackOffice page. */}
            <style>{`
                @media print {
                    @page { size: landscape; margin: 12mm; }
                    body { background: white; }
                }
            `}</style>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 print:hidden">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Stock Inventory by Location</h1>
                    <p className="text-sm text-slate-500 mt-1">On-hand quantity and cost value for every product, broken down by location.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <a href={exportUrl} className="btn-secondary py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                            <path fillRule="evenodd" d="M10 3a.75.75 0 0 1 .75.75v6.638l1.96-2.158a.75.75 0 1 1 1.08 1.04l-3.25 3.5a.75.75 0 0 1-1.08 0l-3.25-3.5a.75.75 0 1 1 1.08-1.04l1.96 2.158V3.75A.75.75 0 0 1 10 3ZM3.5 12.75a.75.75 0 0 1 .75.75v2.5c0 .138.112.25.25.25h11a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 15.5 18h-11A1.75 1.75 0 0 1 2.75 16v-2.5a.75.75 0 0 1 .75-.75Z" clipRule="evenodd" />
                        </svg>
                        Export CSV
                    </a>
                    <button onClick={() => window.print()} className="btn-primary py-2">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                            <path fillRule="evenodd" d="M5 2.75C5 1.784 5.784 1 6.75 1h6.5c.966 0 1.75.784 1.75 1.75v3.552c.377.046.752.097 1.126.153A2.212 2.212 0 0 1 18 8.653v4.097A2.25 2.25 0 0 1 15.75 15h-.241l.305 3.05a.75.75 0 0 1-.746.825H4.932a.75.75 0 0 1-.746-.825L4.491 15H4.25A2.25 2.25 0 0 1 2 12.75V8.653c0-1.081.775-2.005 1.874-2.198.374-.056.75-.107 1.126-.153V2.75Zm8.5 3.397a41.533 41.533 0 0 0-7 0V2.75a.25.25 0 0 1 .25-.25h6.5a.25.25 0 0 1 .25.25v3.397ZM6.006 15l-.245 2.45h8.478L14 15H6.006Z" clipRule="evenodd" />
                        </svg>
                        Print
                    </button>
                </div>
            </div>

            {/* Print-only heading — the on-screen header above is hidden when printing. */}
            <div className="hidden print:block mb-4">
                <h1 className="text-lg font-bold text-slate-900">Stock Inventory by Location</h1>
                <p className="text-xs text-slate-500">As of {asOfDate}</p>
            </div>

            <div className="flex flex-wrap items-end gap-3 mb-6 print:hidden">
                <div>
                    <label className="block text-xs font-semibold text-slate-500 mb-1">Search</label>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilter()}
                        placeholder="Product name or SKU…"
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 w-56"
                    />
                </div>
                <div>
                    <label className="block text-xs font-semibold text-slate-500 mb-1">Category</label>
                    <select
                        value={categoryId}
                        onChange={(e) => { setCategoryId(e.target.value); applyFilter({ category_id: e.target.value }); }}
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                </div>
                <label className="flex items-center gap-2 text-sm text-slate-600 pb-2.5">
                    <input
                        type="checkbox"
                        checked={hideZero}
                        onChange={(e) => { setHideZero(e.target.checked); applyFilter({ hide_zero: e.target.checked }); }}
                        className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                    />
                    Hide zero-stock products
                </label>
                <button onClick={() => applyFilter()} className="btn-primary py-2">Apply</button>
            </div>

            {locations.length === 0 ? (
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-10 text-center text-sm text-slate-400">
                    No locations to report on.
                </div>
            ) : (
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden print:border-0 print:shadow-none print:rounded-none">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm border-collapse">
                            <thead>
                                <tr className="bg-slate-50">
                                    <th
                                        rowSpan={2}
                                        className="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider align-bottom print:static"
                                    >
                                        Product
                                    </th>
                                    <th rowSpan={2} className="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider align-bottom">SKU</th>
                                    <th rowSpan={2} className="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider align-bottom">Category</th>
                                    {locations.map((loc) => (
                                        <th
                                            key={loc.id}
                                            colSpan={2}
                                            className="px-4 py-2 text-center text-xs font-semibold text-slate-600 uppercase tracking-wider border-l border-slate-100"
                                        >
                                            {loc.name}
                                        </th>
                                    ))}
                                    <th colSpan={2} className="px-4 py-2 text-center text-xs font-semibold text-slate-900 uppercase tracking-wider border-l-2 border-slate-200">
                                        Total
                                    </th>
                                </tr>
                                <tr className="bg-slate-50">
                                    {locations.map((loc) => (
                                        <React.Fragment key={loc.id}>
                                            <th className="px-3 py-2 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider border-l border-slate-100">Qty</th>
                                            <th className="px-3 py-2 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Value</th>
                                        </React.Fragment>
                                    ))}
                                    <th className="px-3 py-2 text-right text-[11px] font-semibold text-slate-500 uppercase tracking-wider border-l-2 border-slate-200">Qty</th>
                                    <th className="px-3 py-2 text-right text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Value</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {rows.map((row) => (
                                    <tr key={row.id} className="hover:bg-slate-50/60">
                                        <td className="sticky left-0 z-10 bg-white px-4 py-3 font-medium text-slate-800 print:static">{row.name}</td>
                                        <td className="px-4 py-3 text-slate-400 font-mono text-xs">{row.sku ?? '—'}</td>
                                        <td className="px-4 py-3 text-slate-500">{row.category ?? '—'}</td>
                                        {locations.map((loc) => {
                                            const cell = row.locations[loc.id] ?? { quantity: 0, value: 0 };
                                            return (
                                                <React.Fragment key={loc.id}>
                                                    <td className="px-3 py-3 text-right text-slate-700 tabular-nums border-l border-slate-50">{fmtQty(cell.quantity)}</td>
                                                    <td className="px-3 py-3 text-right text-slate-500 tabular-nums">{fmtValue(cell.value, currency)}</td>
                                                </React.Fragment>
                                            );
                                        })}
                                        <td className="px-3 py-3 text-right font-semibold text-slate-900 tabular-nums border-l-2 border-slate-200">{fmtQty(row.total_quantity)}</td>
                                        <td className="px-3 py-3 text-right font-semibold text-slate-900 tabular-nums">{fmtValue(row.total_value, currency)}</td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={3 + locations.length * 2 + 2} className="px-6 py-10 text-center text-sm text-slate-400">
                                            No trackable products match these filters.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            {rows.length > 0 && (
                                <tfoot>
                                    <tr className="border-t-2 border-slate-200 font-semibold bg-slate-50">
                                        <td className="sticky left-0 z-10 bg-slate-50 px-4 py-3 text-slate-900 print:static" colSpan={3}>Total</td>
                                        {locations.map((loc) => {
                                            const cell = totals.locations[loc.id] ?? { quantity: 0, value: 0 };
                                            return (
                                                <React.Fragment key={loc.id}>
                                                    <td className="px-3 py-3 text-right text-slate-800 tabular-nums border-l border-slate-100">{fmtQty(cell.quantity)}</td>
                                                    <td className="px-3 py-3 text-right text-slate-800 tabular-nums">{fmtValue(cell.value, currency)}</td>
                                                </React.Fragment>
                                            );
                                        })}
                                        <td className="px-3 py-3 text-right text-slate-900 tabular-nums border-l-2 border-slate-200">{fmtQty(totals.quantity)}</td>
                                        <td className="px-3 py-3 text-right text-slate-900 tabular-nums">{fmtValue(totals.value, currency)}</td>
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </div>
            )}
        </BackOfficeLayout>
    );
}
