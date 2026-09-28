import React from 'react';
import { Head } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface Row {
    quotation_id: string;
    quote_number: string;
    customer_name: string;
    status: 'draft' | 'sent';
    product_name: string;
    quantity_quoted: number;
    available_now: number | null;
    shortfall: number;
}

interface Props {
    rows: Row[];
}

const fmt = (n: number) => (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2));

export default function BackOfficeQuotationStock({ rows }: Props) {
    const shortfallCount = rows.filter((r) => r.shortfall > 0).length;

    return (
        <BackOfficeLayout>
            <Head title="Quoted vs In-Stock" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Quoted vs In-Stock</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Every line on a still-open quotation (draft or sent), checked against stock right now — catches
                    quotes that were fine when written but can no longer be fully honoured.
                    {shortfallCount > 0 && (
                        <span className="ml-1 font-semibold text-amber-600">
                            {shortfallCount} line{shortfallCount === 1 ? '' : 's'} short.
                        </span>
                    )}
                </p>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[720px]">
                        <thead>
                            <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide border-b border-slate-100">
                                <th className="px-5 py-3">Quote</th>
                                <th className="px-5 py-3">Customer</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3">Product</th>
                                <th className="px-5 py-3 text-right">Quoted</th>
                                <th className="px-5 py-3 text-right">In Stock Now</th>
                                <th className="px-5 py-3 text-right">Shortfall</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {rows.map((row, i) => (
                                <tr key={`${row.quotation_id}-${i}`} className={row.shortfall > 0 ? 'bg-amber-50/50' : undefined}>
                                    <td className="px-5 py-3 font-medium text-slate-800">{row.quote_number}</td>
                                    <td className="px-5 py-3 text-slate-600">{row.customer_name}</td>
                                    <td className="px-5 py-3">
                                        <StatusBadge
                                            label={row.status === 'draft' ? 'Draft' : 'Sent'}
                                            variant={row.status === 'draft' ? 'gray' : 'blue'}
                                        />
                                    </td>
                                    <td className="px-5 py-3 text-slate-700">{row.product_name}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(row.quantity_quoted)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">
                                        {row.available_now === null ? '—' : fmt(row.available_now)}
                                    </td>
                                    <td className={`px-5 py-3 text-right tabular-nums font-semibold ${row.shortfall > 0 ? 'text-amber-700' : 'text-slate-300'}`}>
                                        {row.shortfall > 0 ? fmt(row.shortfall) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && (
                        <p className="px-5 py-10 text-center text-sm text-slate-400">
                            No open quotations with items right now.
                        </p>
                    )}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
