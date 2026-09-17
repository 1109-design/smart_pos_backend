import React from 'react';
import { Head, Link } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface ActivityRow {
    date: string;
    description: string | null;
    debit: number;
    credit: number;
    running_balance: number;
}

interface BankAccountRow {
    id: string;
    name: string;
    account_number: string | null;
    branch: string | null;
    currency_code: string;
}

interface Props {
    account: BankAccountRow;
    balance: number;
    activity: ActivityRow[];
}

const fmt = (n: number) => n.toFixed(2);

export default function BankAccountCashBook({ account, balance, activity }: Props) {
    return (
        <BackOfficeLayout>
            <Head title={`${account.name} — Cash Book`} />

            <div className="mb-6 flex items-start justify-between">
                <div>
                    <Link href="/office/bank-accounts" className="text-xs font-semibold text-emerald-700 hover:underline">
                        ← Bank Accounts
                    </Link>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight mt-2">{account.name}</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        {[account.account_number, account.branch].filter(Boolean).join(' · ') || 'Cash book'}
                    </p>
                </div>
                <Link href={`/office/bank-accounts/${account.id}/reconcile`} className="btn-primary py-2 px-4">
                    Reconcile
                </Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-1">
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                        <p className="text-xs font-semibold text-slate-400">Balance</p>
                        <p className="text-3xl font-bold text-slate-900 mt-1">{fmt(balance)}</p>
                        <p className="text-xs text-slate-400 mt-1">{account.currency_code}</p>
                    </div>
                </div>

                <div className="lg:col-span-2 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-800">Activity</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                    <th className="px-5 py-2.5">Date</th>
                                    <th className="px-5 py-2.5">Description</th>
                                    <th className="px-5 py-2.5 text-right">Debit</th>
                                    <th className="px-5 py-2.5 text-right">Credit</th>
                                    <th className="px-5 py-2.5 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {activity.map((row, i) => (
                                    <tr key={i}>
                                        <td className="px-5 py-3 text-slate-500 whitespace-nowrap">
                                            {new Date(row.date).toLocaleDateString()}
                                        </td>
                                        <td className="px-5 py-3 text-slate-700">{row.description ?? '—'}</td>
                                        <td className="px-5 py-3 text-right text-slate-700 tabular-nums">
                                            {row.debit > 0.005 ? fmt(row.debit) : ''}
                                        </td>
                                        <td className="px-5 py-3 text-right text-slate-700 tabular-nums">
                                            {row.credit > 0.005 ? fmt(row.credit) : ''}
                                        </td>
                                        <td className="px-5 py-3 text-right font-semibold text-slate-900 tabular-nums">
                                            {fmt(row.running_balance)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {activity.length === 0 && (
                            <p className="px-5 py-10 text-center text-sm text-slate-400">No activity yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
