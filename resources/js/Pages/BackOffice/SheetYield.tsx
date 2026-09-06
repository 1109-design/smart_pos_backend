import React from 'react';
import { Head } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface LotRow {
    id: string;
    product_name: string;
    product_sku: string | null;
    original_width: number | null;
    original_height: number | null;
    original_area: number;
    cut_area: number;
    remaining_area: number;
    cut_count: number;
    status: 'available' | 'exhausted';
    received_at: string;
}

interface ProductSummary {
    name: string;
    lot_count: number;
    total_remaining_area: number;
}

interface Props {
    lots: LotRow[];
    product_summaries: ProductSummary[];
}

const fmt = (n: number) => n.toFixed(3);

export default function BackOfficeSheetYield({ lots, product_summaries }: Props) {
    return (
        <BackOfficeLayout>
            <Head title="Sheet Yield" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Sheet Yield</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Every physical sheet ever received, and how much of it is left after cuts — nothing between the
                    invoice and the offcuts bin goes untracked.
                </p>
            </div>

            {product_summaries.length > 0 && (
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                    {product_summaries.map((p) => (
                        <div key={p.name} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{p.name}</p>
                            <p className="text-xl font-bold text-slate-900 mt-1.5">{fmt(p.total_remaining_area)} m² left</p>
                            <p className="text-xs text-slate-500 mt-0.5">{p.lot_count} sheet{p.lot_count === 1 ? '' : 's'} on hand</p>
                        </div>
                    ))}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-3">Product</th>
                                <th className="px-5 py-3">Original Size</th>
                                <th className="px-5 py-3 text-right">Purchased Area</th>
                                <th className="px-5 py-3 text-right">Cut So Far</th>
                                <th className="px-5 py-3 text-right">Remaining</th>
                                <th className="px-5 py-3 text-right">Cuts</th>
                                <th className="px-5 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {lots.map((lot) => (
                                <tr key={lot.id}>
                                    <td className="px-5 py-3 text-slate-800">
                                        {lot.product_name}
                                        {lot.product_sku && <span className="text-slate-400"> ({lot.product_sku})</span>}
                                    </td>
                                    <td className="px-5 py-3 text-slate-600">
                                        {lot.original_width !== null && lot.original_height !== null
                                            ? `${lot.original_width} × ${lot.original_height}`
                                            : '—'}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(lot.original_area)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(lot.cut_area)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums font-semibold text-slate-900">{fmt(lot.remaining_area)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-500">{lot.cut_count}</td>
                                    <td className="px-5 py-3">
                                        <StatusBadge
                                            label={lot.status === 'available' ? 'Available' : 'Exhausted'}
                                            variant={lot.status === 'available' ? 'green' : 'gray'}
                                        />
                                    </td>
                                </tr>
                            ))}
                            {lots.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-5 py-10 text-center text-slate-400">
                                        No sheets received yet — receive stock for a sheet-tracked product to see it here.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
