import React from 'react';
import { Head, Link } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface Row {
    id: string;
    name: string;
    buckets: { current: number; days_31_60: number; days_61_90: number; days_91_120: number; over_120: number };
    total_outstanding: number;
    credit_balance: number;
}

interface Props {
    type: 'debtor' | 'creditor';
    show_route_prefix: string;
    rows: Row[];
}

const fmt = (n: number) => n.toFixed(2);

export default function BackOfficeAgeAnalysis({ type, show_route_prefix, rows }: Props) {
    const title = type === 'debtor' ? 'Debtor Age Analysis' : 'Creditor Age Analysis';
    const subtitle = type === 'debtor'
        ? 'Who owes you money, and how overdue it is.'
        : 'Who you owe money to, and how overdue it is.';

    const totals = rows.reduce(
        (acc, r) => ({
            current: acc.current + r.buckets.current,
            days_31_60: acc.days_31_60 + r.buckets.days_31_60,
            days_61_90: acc.days_61_90 + r.buckets.days_61_90,
            days_91_120: acc.days_91_120 + r.buckets.days_91_120,
            over_120: acc.over_120 + r.buckets.over_120,
            total_outstanding: acc.total_outstanding + r.total_outstanding,
        }),
        { current: 0, days_31_60: 0, days_61_90: 0, days_91_120: 0, over_120: 0, total_outstanding: 0 }
    );

    return (
        <BackOfficeLayout>
            <Head title={title} />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{title}</h1>
                <p className="text-sm text-slate-500 mt-1">{subtitle}</p>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide border-b border-slate-100">
                                <th className="px-5 py-3">Name</th>
                                <th className="px-5 py-3 text-right">Current</th>
                                <th className="px-5 py-3 text-right">31–60</th>
                                <th className="px-5 py-3 text-right">61–90</th>
                                <th className="px-5 py-3 text-right">91–120</th>
                                <th className="px-5 py-3 text-right">120+</th>
                                <th className="px-5 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-5 py-3">
                                        <Link
                                            href={`${show_route_prefix}/${row.id}`}
                                            className="font-medium text-slate-800 hover:text-emerald-700 hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        {row.credit_balance > 0.005 && (
                                            <span className="ml-2 text-xs text-emerald-600">
                                                ({fmt(row.credit_balance)} credit)
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(row.buckets.current)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(row.buckets.days_31_60)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(row.buckets.days_61_90)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-amber-600">{fmt(row.buckets.days_91_120)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-red-600">{fmt(row.buckets.over_120)}</td>
                                    <td className="px-5 py-3 text-right font-semibold tabular-nums text-slate-900">{fmt(row.total_outstanding)}</td>
                                </tr>
                            ))}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-slate-100 font-semibold">
                                    <td className="px-5 py-3 text-slate-800">Total</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-800">{fmt(totals.current)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-800">{fmt(totals.days_31_60)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-800">{fmt(totals.days_61_90)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-amber-700">{fmt(totals.days_91_120)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-red-700">{fmt(totals.over_120)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-900">{fmt(totals.total_outstanding)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                    {rows.length === 0 && (
                        <p className="px-5 py-10 text-center text-sm text-slate-400">
                            No outstanding balances{type === 'debtor' ? ' from customers' : ' to suppliers'} right now.
                        </p>
                    )}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
