import React from 'react';
import { Head, Link } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface CurrencyRow {
    code: string;
    name: string;
    symbol: string;
    current_rate: number | null;
    set_by: string | null;
    valid_from: string | null;
    change_count: number;
}

interface Props {
    base_currency: string | null;
    currencies: CurrencyRow[];
}

export default function BackOfficeExchangeRates({ base_currency, currencies }: Props) {
    return (
        <BackOfficeLayout>
            <Head title="Exchange Rates" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Exchange Rates</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Rates are set from the till and take effect immediately on approval — every change is kept, never
                    overwritten. Open a currency to see its full audit history.
                </p>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[640px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Currency</th>
                                <th className="table-th text-right">Current rate</th>
                                <th className="table-th">Set by</th>
                                <th className="table-th">Active since</th>
                                <th className="table-th text-right">Changes</th>
                                <th className="table-th" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {currencies.map((c) => (
                                <tr key={c.code}>
                                    <td className="table-td text-slate-700 font-medium">
                                        {c.symbol} {c.name} ({c.code})
                                    </td>
                                    <td className="table-td text-right text-slate-700">
                                        {c.current_rate !== null
                                            ? `1 ${base_currency ?? ''} = ${c.current_rate.toFixed(4)} ${c.code}`
                                            : <span className="text-slate-400">No rate set</span>}
                                    </td>
                                    <td className="table-td text-slate-600">{c.set_by ?? '—'}</td>
                                    <td className="table-td text-slate-500">
                                        {c.valid_from ? new Date(c.valid_from).toLocaleString() : '—'}
                                    </td>
                                    <td className="table-td text-right text-slate-500">{c.change_count}</td>
                                    <td className="table-td text-right">
                                        <Link
                                            href={`/office/exchange-rates/${c.code}`}
                                            className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                        >
                                            History →
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {currencies.length === 0 && (
                                <tr>
                                    <td className="table-td text-center text-slate-400" colSpan={6}>
                                        No non-base currencies configured yet.
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
