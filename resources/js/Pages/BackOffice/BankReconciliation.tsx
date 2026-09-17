import React from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface BankAccountRow {
    id: string;
    name: string;
    currency_code: string;
}

interface ReconciliationRow {
    id: string;
    statement_date: string;
    statement_balance: number;
    status: string;
}

interface LedgerLineRow {
    id: string;
    date: string;
    description: string | null;
    debit: number;
    credit: number;
}

interface Props {
    account: BankAccountRow;
    reconciliation: ReconciliationRow | null;
    unreconciledLines: LedgerLineRow[];
    clearedBalance: number | null;
}

const fmt = (n: number) => n.toFixed(2);
const today = () => new Date().toISOString().slice(0, 10);

export default function BankReconciliation({ account, reconciliation, unreconciledLines, clearedBalance }: Props) {
    if (!reconciliation) {
        return <StartForm account={account} />;
    }

    const difference = (clearedBalance ?? 0) - Number(reconciliation.statement_balance);
    const canComplete = Math.abs(difference) <= 0.005;

    const toggle = (entryId: string, cleared: boolean) => {
        router.post(`/office/bank-accounts/${account.id}/reconcile/toggle`, { entry_id: entryId, cleared }, { preserveScroll: true });
    };

    const complete = () => {
        router.post(`/office/bank-accounts/${account.id}/reconcile/complete`, {}, { preserveScroll: true });
    };

    const cancel = () => {
        if (!confirm('Cancel this reconciliation? Any ticked lines return to the unreconciled pool.')) return;
        router.post(`/office/bank-accounts/${account.id}/reconcile/cancel`, {}, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title={`Reconcile ${account.name}`} />

            <div className="mb-6 flex items-start justify-between">
                <div>
                    <Link href={`/office/bank-accounts/${account.id}/cash-book`} className="text-xs font-semibold text-emerald-700 hover:underline">
                        ← {account.name}
                    </Link>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight mt-2">
                        Reconcile as of {new Date(reconciliation.statement_date).toLocaleDateString()}
                    </h1>
                </div>
                <Link
                    href={`/office/bank-accounts/${account.id}/reconciliation-history`}
                    className="text-xs font-semibold text-slate-500 hover:underline"
                >
                    View History
                </Link>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <SummaryCard label="Statement Balance" value={fmt(Number(reconciliation.statement_balance))} />
                <SummaryCard label="Cleared Balance" value={fmt(clearedBalance ?? 0)} />
                <SummaryCard
                    label="Difference"
                    value={fmt(difference)}
                    tone={canComplete ? 'good' : 'warn'}
                />
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden mb-6">
                <div className="px-5 py-4 border-b border-slate-100">
                    <p className="text-sm font-semibold text-slate-800">Unreconciled Lines</p>
                    <p className="text-xs text-slate-400 mt-0.5">Tick off each line as it appears on the bank statement.</p>
                </div>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                            <th className="px-5 py-2.5 w-10" />
                            <th className="px-5 py-2.5">Date</th>
                            <th className="px-5 py-2.5">Description</th>
                            <th className="px-5 py-2.5 text-right">Debit</th>
                            <th className="px-5 py-2.5 text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {unreconciledLines.map((line) => (
                            <tr key={line.id}>
                                <td className="px-5 py-3">
                                    <input type="checkbox" onChange={(e) => toggle(line.id, e.target.checked)} />
                                </td>
                                <td className="px-5 py-3 text-slate-500 whitespace-nowrap">
                                    {new Date(line.date).toLocaleDateString()}
                                </td>
                                <td className="px-5 py-3 text-slate-700">{line.description ?? '—'}</td>
                                <td className="px-5 py-3 text-right text-slate-700 tabular-nums">
                                    {line.debit > 0.005 ? fmt(line.debit) : ''}
                                </td>
                                <td className="px-5 py-3 text-right text-slate-700 tabular-nums">
                                    {line.credit > 0.005 ? fmt(line.credit) : ''}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {unreconciledLines.length === 0 && (
                    <p className="px-5 py-10 text-center text-sm text-slate-400">Nothing left to tick off.</p>
                )}
            </div>

            <div className="flex gap-3">
                <button onClick={complete} disabled={!canComplete} className="btn-primary py-2 px-6 disabled:opacity-50">
                    Complete Reconciliation
                </button>
                <button onClick={cancel} className="text-sm font-semibold text-red-500 hover:underline">
                    Cancel Reconciliation
                </button>
            </div>
        </BackOfficeLayout>
    );
}

function StartForm({ account }: { account: BankAccountRow }) {
    const form = useForm({ statement_date: today(), statement_balance: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/office/bank-accounts/${account.id}/reconcile/start`, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title={`Reconcile ${account.name}`} />

            <div className="mb-6">
                <Link href={`/office/bank-accounts/${account.id}/cash-book`} className="text-xs font-semibold text-emerald-700 hover:underline">
                    ← {account.name}
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight mt-2">Start a Reconciliation</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Enter the ending balance from your bank statement, then tick off each cash-book line as you find it.
                </p>
            </div>

            <form onSubmit={submit} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 max-w-sm space-y-4">
                <div>
                    <label className="text-xs font-semibold text-slate-500">Statement date</label>
                    <input
                        type="date" required
                        value={form.data.statement_date}
                        onChange={(e) => form.setData('statement_date', e.target.value)}
                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    />
                </div>
                <div>
                    <label className="text-xs font-semibold text-slate-500">Statement ending balance</label>
                    <input
                        type="number" step="0.01" required
                        value={form.data.statement_balance}
                        onChange={(e) => form.setData('statement_balance', e.target.value)}
                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    />
                    {form.errors.statement_balance && <p className="text-xs text-red-500 mt-1">{form.errors.statement_balance}</p>}
                </div>
                <button type="submit" disabled={form.processing} className="btn-primary py-2 w-full disabled:opacity-50">
                    {form.processing ? 'Starting…' : 'Start Reconciliation'}
                </button>
            </form>
        </BackOfficeLayout>
    );
}

function SummaryCard({ label, value, tone }: { label: string; value: string; tone?: 'good' | 'warn' }) {
    const color = tone === 'good' ? 'text-emerald-700' : tone === 'warn' ? 'text-amber-600' : 'text-slate-900';
    return (
        <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p className="text-xs font-semibold text-slate-400">{label}</p>
            <p className={`text-2xl font-bold mt-1 ${color}`}>{value}</p>
        </div>
    );
}
