import React from 'react';
import { Head, Link } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface BankAccountRow {
    id: string;
    name: string;
}

interface SessionRow {
    id: string;
    statement_date: string;
    statement_balance: number;
    status: string;
    completed_at: string | null;
}

interface Props {
    account: BankAccountRow;
    sessions: SessionRow[];
}

const fmt = (n: number) => n.toFixed(2);

const statusStyle = (status: string) => {
    if (status === 'completed') return 'bg-emerald-50 text-emerald-700';
    if (status === 'cancelled') return 'bg-slate-100 text-slate-500';
    return 'bg-amber-50 text-amber-700';
};

export default function ReconciliationHistory({ account, sessions }: Props) {
    return (
        <BackOfficeLayout>
            <Head title={`${account.name} — Reconciliation History`} />

            <div className="mb-6">
                <Link href={`/office/bank-accounts/${account.id}/cash-book`} className="text-xs font-semibold text-emerald-700 hover:underline">
                    ← {account.name}
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight mt-2">Reconciliation History</h1>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                            <th className="px-5 py-2.5">Statement Date</th>
                            <th className="px-5 py-2.5 text-right">Statement Balance</th>
                            <th className="px-5 py-2.5">Status</th>
                            <th className="px-5 py-2.5">Completed</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {sessions.map((s) => (
                            <tr key={s.id}>
                                <td className="px-5 py-3 text-slate-700 whitespace-nowrap">
                                    {new Date(s.statement_date).toLocaleDateString()}
                                </td>
                                <td className="px-5 py-3 text-right text-slate-700 tabular-nums">{fmt(Number(s.statement_balance))}</td>
                                <td className="px-5 py-3">
                                    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full capitalize ${statusStyle(s.status)}`}>
                                        {s.status.replace('_', ' ')}
                                    </span>
                                </td>
                                <td className="px-5 py-3 text-slate-500">
                                    {s.completed_at ? new Date(s.completed_at).toLocaleDateString() : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {sessions.length === 0 && (
                    <p className="px-5 py-10 text-center text-sm text-slate-400">No reconciliations yet.</p>
                )}
            </div>
        </BackOfficeLayout>
    );
}
