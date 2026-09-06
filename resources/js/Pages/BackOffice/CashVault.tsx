import React, { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface ActivityRow {
    date: string;
    description: string | null;
    debit: number;
    credit: number;
    running_balance: number;
}

interface Props {
    balance: number;
    activity: ActivityRow[];
}

const fmt = (n: number) => n.toFixed(2);
const today = () => new Date().toISOString().slice(0, 10);

type Tab = 'drop' | 'deposit' | 'count';

export default function BackOfficeCashVault({ balance, activity }: Props) {
    const [tab, setTab] = useState<Tab>('drop');
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    return (
        <BackOfficeLayout>
            <Head title="Cash Vault" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Cash Vault</h1>
                <p className="text-sm text-slate-500 mt-1">
                    The safe between the till drawer and the bank. Balance is in base currency only — see the note below.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-1 space-y-6">
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                        <p className="text-xs font-semibold text-slate-400">Vault balance</p>
                        <p className="text-3xl font-bold text-slate-900 mt-1">{fmt(balance)}</p>
                    </div>

                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="flex border-b border-slate-100">
                            {(['drop', 'deposit', 'count'] as Tab[]).map((t) => (
                                <button
                                    key={t}
                                    onClick={() => setTab(t)}
                                    className={`flex-1 text-xs font-semibold py-3 capitalize ${
                                        tab === t ? 'text-emerald-700 border-b-2 border-emerald-600' : 'text-slate-400'
                                    }`}
                                >
                                    {t === 'drop' ? 'Till drop' : t === 'deposit' ? 'Bank deposit' : 'Count'}
                                </button>
                            ))}
                        </div>
                        <div className="p-5">
                            {tab === 'drop' && <MoveForm action="/office/cash-vault/drop" cta="Record Drop" helper="Cash physically moved from a till drawer into the safe." />}
                            {tab === 'deposit' && <MoveForm action="/office/cash-vault/deposit" cta="Record Deposit" helper="The safe was emptied and banked." />}
                            {tab === 'count' && <CountForm currentBalance={balance} />}
                        </div>
                    </div>

                    <p className="text-xs text-slate-400 leading-relaxed">
                        This balance is blended across currencies at their base-currency equivalent — sales only ever post
                        their base-currency amount, so a true per-currency vault split isn't something the books can
                        answer precisely yet.
                    </p>
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
                            <p className="px-5 py-10 text-center text-sm text-slate-400">No vault activity yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </BackOfficeLayout>
    );
}

function MoveForm({ action, cta, helper }: { action: string; cta: string; helper: string }) {
    const form = useForm({ amount: '', date: today(), note: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(action, { preserveScroll: true, onSuccess: () => form.reset('amount', 'note') });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <p className="text-xs text-slate-500">{helper}</p>
            <div>
                <label className="text-xs font-semibold text-slate-500">Amount</label>
                <input
                    type="number" step="0.01" min="0.01" required
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
                {form.errors.amount && <p className="text-xs text-red-500 mt-1">{form.errors.amount}</p>}
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Date</label>
                <input
                    type="date" required
                    value={form.data.date}
                    onChange={(e) => form.setData('date', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Note (optional)</label>
                <input
                    type="text"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <button type="submit" disabled={form.processing} className="btn-primary py-2 w-full disabled:opacity-50">
                {form.processing ? 'Saving…' : cta}
            </button>
        </form>
    );
}

function CountForm({ currentBalance }: { currentBalance: number }) {
    const form = useForm({ counted_amount: currentBalance.toFixed(2), date: today() });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/cash-vault/count', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <p className="text-xs text-slate-500">
                Enter what's physically in the safe. Any difference from the ledger posts to Cash Vault Variance.
            </p>
            <div>
                <label className="text-xs font-semibold text-slate-500">Counted amount</label>
                <input
                    type="number" step="0.01" min="0" required
                    value={form.data.counted_amount}
                    onChange={(e) => form.setData('counted_amount', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
                {form.errors.counted_amount && <p className="text-xs text-red-500 mt-1">{form.errors.counted_amount}</p>}
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Date</label>
                <input
                    type="date" required
                    value={form.data.date}
                    onChange={(e) => form.setData('date', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <button type="submit" disabled={form.processing} className="btn-primary py-2 w-full disabled:opacity-50">
                {form.processing ? 'Saving…' : 'Reconcile Count'}
            </button>
        </form>
    );
}
