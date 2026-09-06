import React, { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface AccountRow {
    code: string;
    name: string;
    category: string | null;
    debit_balance: number;
    credit_balance: number;
}

interface Report {
    accounts: AccountRow[];
    total_debit: number;
    total_credit: number;
    is_balanced: boolean;
}

interface Props {
    as_of: string;
    report: Report;
}

const fmt = (n: number) => n.toFixed(2);

export default function BackOfficeTrialBalance({ as_of, report }: Props) {
    const [asOf, setAsOf] = useState(as_of);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get('/office/reports/trial-balance', { as_of: asOf }, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Trial Balance" />

            <div className="mb-6 flex items-end justify-between flex-wrap gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Trial Balance</h1>
                    <p className="text-sm text-slate-500 mt-1">Every account's balance as of a date — should always balance.</p>
                </div>
                <form onSubmit={submit} className="flex items-end gap-2">
                    <div>
                        <label className="block text-xs font-semibold text-slate-500 mb-1">As of</label>
                        <input
                            type="date"
                            value={asOf}
                            onChange={(e) => setAsOf(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <button type="submit" className="btn-primary py-2 px-4">Run</button>
                </form>
            </div>

            <div
                className={`mb-4 rounded-xl border px-4 py-3 text-sm ${
                    report.is_balanced
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'border-red-200 bg-red-50 text-red-700'
                }`}
            >
                {report.is_balanced
                    ? 'Balanced — total debits equal total credits.'
                    : `Out of balance by ${fmt(Math.abs(report.total_debit - report.total_credit))}. This should never happen — check for a data issue.`}
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide border-b border-slate-100">
                                <th className="px-5 py-3">Code</th>
                                <th className="px-5 py-3">Account</th>
                                <th className="px-5 py-3">Category</th>
                                <th className="px-5 py-3 text-right">Debit</th>
                                <th className="px-5 py-3 text-right">Credit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {report.accounts.map((row) => (
                                <tr key={row.code}>
                                    <td className="px-5 py-3 text-slate-400 tabular-nums">{row.code}</td>
                                    <td className="px-5 py-3 text-slate-800 font-medium">{row.name}</td>
                                    <td className="px-5 py-3 text-slate-500">{row.category}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-700">
                                        {row.debit_balance > 0.005 ? fmt(row.debit_balance) : ''}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-700">
                                        {row.credit_balance > 0.005 ? fmt(row.credit_balance) : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        {report.accounts.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-slate-100 font-semibold">
                                    <td className="px-5 py-3" colSpan={3}>Total</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-900">{fmt(report.total_debit)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-900">{fmt(report.total_credit)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                    {report.accounts.length === 0 && (
                        <p className="px-5 py-10 text-center text-sm text-slate-400">No activity posted yet.</p>
                    )}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
