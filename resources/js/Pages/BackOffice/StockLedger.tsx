import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface Movement {
    id: string;
    product_id: string;
    product_name: string;
    product_sku: string | null;
    type: string;
    quantity_change: string;
    unit_cost: string | null;
    running_avg_cost: string | null;
    reason: string | null;
    reference_id: string | null;
    location_id: string | null;
    to_location_id: string | null;
    user_id: string;
    user_name: string;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated {
    data: Movement[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

interface ProductOption {
    id: string;
    name: string;
    sku: string | null;
}

interface LocationOption {
    id: string;
    name: string;
}

interface Filters {
    from: string;
    to: string;
    product_id?: string;
    type?: string;
    location_id?: string;
    search?: string;
}

interface Props {
    movements: Paginated;
    filters: Filters;
    currency: string;
    products: ProductOption[];
    locations: LocationOption[];
}

const TYPE_STYLES: Record<string, string> = {
    receive:     'bg-green-100 text-green-800',
    sale:        'bg-indigo-100 text-indigo-800',
    return:      'bg-amber-100 text-amber-800',
    adjustment:  'bg-purple-100 text-purple-800',
    writeoff:    'bg-red-100 text-red-800',
    transfer:    'bg-sky-100 text-sky-800',
    stocktake:   'bg-teal-100 text-teal-800',
};

const TYPES = ['sale', 'receive', 'adjustment', 'return', 'writeoff', 'transfer', 'stocktake'];

function TypeBadge({ type }: { type: string }) {
    const cls = TYPE_STYLES[type] ?? 'bg-slate-100 text-slate-700';
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold capitalize ${cls}`}>
            {type}
        </span>
    );
}

function fmt(val: string | null, currency: string): string {
    if (val === null || val === undefined) {
        return '—';
    }
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(val));
}

export default function StockLedger({ movements, filters, currency, products, locations }: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [productId, setProductId] = useState(filters.product_id ?? '');
    const [type, setType] = useState(filters.type ?? '');
    const [locationId, setLocationId] = useState(filters.location_id ?? '');
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilter = () => {
        const params: Record<string, string> = { from, to };
        if (productId) params.product_id = productId;
        if (type) params.type = type;
        if (locationId) params.location_id = locationId;
        if (search) params.search = search;
        router.get('/office/stock-ledger', params, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Stock Ledger" />

            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Stock Ledger</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        {new Date(filters.from).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                        {' — '}
                        {new Date(filters.to).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}
                        {' · '}
                        <span className="font-medium text-slate-700">{movements.total}</span> movements
                    </p>
                </div>
            </div>

            {/* Filter bar */}
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 mb-6">
                <div className="flex flex-wrap gap-3 items-end">
                    <div className="flex flex-col gap-1">
                        <label className="text-xs font-semibold text-slate-500 uppercase tracking-wide">From</label>
                        <input
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label className="text-xs font-semibold text-slate-500 uppercase tracking-wide">To</label>
                        <input
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Type</label>
                        <select
                            value={type}
                            onChange={(e) => setType(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        >
                            <option value="">All types</option>
                            {TYPES.map((t) => (
                                <option key={t} value={t} className="capitalize">{t}</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Product</label>
                        <select
                            value={productId}
                            onChange={(e) => setProductId(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        >
                            <option value="">All products</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>{p.name}{p.sku ? ` (${p.sku})` : ''}</option>
                            ))}
                        </select>
                    </div>
                    {locations.length > 0 && (
                        <div className="flex flex-col gap-1">
                            <label className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Location</label>
                            <select
                                value={locationId}
                                onChange={(e) => setLocationId(e.target.value)}
                                className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            >
                                <option value="">All locations</option>
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>{l.name}</option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="flex flex-col gap-1">
                        <label className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Search</label>
                        <input
                            type="text"
                            placeholder="Product name or SKU…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilter()}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 min-w-[180px]"
                        />
                    </div>
                    <button onClick={applyFilter} className="btn-primary py-2 self-end">Apply</button>
                </div>
            </div>

            {/* Table */}
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[900px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Date / Time</th>
                                <th className="table-th">Product</th>
                                <th className="table-th">Type</th>
                                <th className="table-th text-right">Qty Change</th>
                                <th className="table-th text-right">Unit Cost</th>
                                <th className="table-th text-right">WAC After</th>
                                <th className="table-th">Reason</th>
                                <th className="table-th">Reference</th>
                                <th className="table-th">User</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {movements.data.map((m) => {
                                const qty = Number(m.quantity_change);
                                const qtyClass = qty >= 0 ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold';
                                return (
                                    <tr key={m.id} className="hover:bg-slate-50/60">
                                        <td className="table-td text-slate-500 whitespace-nowrap font-mono text-xs">
                                            {new Date(m.created_at).toLocaleString(undefined, {
                                                month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
                                            })}
                                        </td>
                                        <td className="table-td">
                                            <p className="font-medium text-slate-800">{m.product_name}</p>
                                            {m.product_sku && (
                                                <p className="text-xs text-slate-400 font-mono">{m.product_sku}</p>
                                            )}
                                        </td>
                                        <td className="table-td">
                                            <TypeBadge type={m.type} />
                                        </td>
                                        <td className={`table-td text-right ${qtyClass}`}>
                                            {qty >= 0 ? `+${qty}` : `${qty}`}
                                        </td>
                                        <td className="table-td text-right text-slate-600">
                                            {fmt(m.unit_cost, currency)}
                                        </td>
                                        <td className="table-td text-right text-slate-600">
                                            {fmt(m.running_avg_cost, currency)}
                                        </td>
                                        <td className="table-td text-slate-500 max-w-[160px] truncate">
                                            {m.reason ?? '—'}
                                        </td>
                                        <td className="table-td text-slate-400 font-mono text-xs">
                                            {m.reference_id ? m.reference_id.slice(0, 8) + '…' : '—'}
                                        </td>
                                        <td className="table-td text-slate-600">{m.user_name}</td>
                                    </tr>
                                );
                            })}
                            {movements.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="px-6 py-10 text-center text-sm text-slate-400">
                                        No stock movements found for the selected filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {movements.last_page > 1 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex items-center justify-between">
                        <p className="text-xs text-slate-500">
                            Page {movements.current_page} of {movements.last_page} · {movements.total} total
                        </p>
                        <div className="flex items-center gap-1">
                            {movements.links.map((link, i) => {
                                if (!link.url) {
                                    return (
                                        <span
                                            key={i}
                                            className="px-3 py-1.5 text-xs rounded-lg text-slate-300"
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    );
                                }
                                return (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`px-3 py-1.5 text-xs rounded-lg font-medium transition-colors ${
                                            link.active
                                                ? 'bg-emerald-600 text-white'
                                                : 'text-slate-600 hover:bg-slate-100'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
        </BackOfficeLayout>
    );
}
