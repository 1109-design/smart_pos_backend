import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface BudgetRow {
    id: string;
    name: string;
    period_start: string;
    period_end: string;
    amount: number;
    spent: number;
    remaining: number;
    is_current: boolean;
}

interface Props {
    budgets: BudgetRow[];
}

const fmt = (n: number) => n.toFixed(2);

export default function BackOfficeProcurementBudgets({ budgets }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const [showNew, setShowNew] = useState(false);
    const [name, setName] = useState('');
    const [periodStart, setPeriodStart] = useState('');
    const [periodEnd, setPeriodEnd] = useState('');
    const [amount, setAmount] = useState('');

    const submit = () => {
        router.post(
            '/office/procurement-budgets',
            { name, period_start: periodStart, period_end: periodEnd, amount },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowNew(false);
                    setName('');
                    setPeriodStart('');
                    setPeriodEnd('');
                    setAmount('');
                },
            }
        );
    };

    const remove = (id: string) => {
        if (!confirm('Remove this budget? Purchase orders raised under it stay as they are — this only stops future ones being checked against it.')) return;
        router.delete(`/office/procurement-budgets/${id}`, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Procurement Budgets" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Procurement Budgets</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        A purchase order that would push a period's committed spend past its budget is held for
                        approval instead of going straight to the supplier — see Approvals.
                    </p>
                </div>
                <button onClick={() => setShowNew(true)} className="btn-primary py-2">+ New Budget</button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-3">Name</th>
                                <th className="px-5 py-3">Period</th>
                                <th className="px-5 py-3 text-right">Budget</th>
                                <th className="px-5 py-3 text-right">Spent</th>
                                <th className="px-5 py-3 text-right">Remaining</th>
                                <th className="px-5 py-3"></th>
                                <th className="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {budgets.map((b) => (
                                <tr key={b.id} className={b.remaining < 0 ? 'bg-red-50/50' : undefined}>
                                    <td className="px-5 py-3 font-medium text-slate-900">{b.name}</td>
                                    <td className="px-5 py-3 text-slate-600 whitespace-nowrap">
                                        {b.period_start} → {b.period_end}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(b.amount)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">{fmt(b.spent)}</td>
                                    <td className={`px-5 py-3 text-right tabular-nums font-semibold ${b.remaining < 0 ? 'text-red-600' : 'text-slate-900'}`}>
                                        {fmt(b.remaining)}
                                    </td>
                                    <td className="px-5 py-3">
                                        {b.is_current && <StatusBadge label="Current" variant="green" />}
                                    </td>
                                    <td className="px-5 py-3 text-right">
                                        <button
                                            onClick={() => remove(b.id)}
                                            className="text-xs font-semibold text-red-600 hover:text-red-800"
                                        >
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {budgets.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-5 py-10 text-center text-slate-400">No budgets set up yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal show={showNew} onClose={() => setShowNew(false)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-slate-900 mb-4">New Procurement Budget</h2>
                    <div className="space-y-3 mb-4">
                        <input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="e.g. September 2026 Purchasing"
                            className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                        />
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Period start</label>
                                <input
                                    type="date"
                                    value={periodStart}
                                    onChange={(e) => setPeriodStart(e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Period end</label>
                                <input
                                    type="date"
                                    value={periodEnd}
                                    onChange={(e) => setPeriodEnd(e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                                />
                            </div>
                        </div>
                        <input
                            type="number"
                            min="0.01"
                            step="0.01"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            placeholder="Budget amount"
                            className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <button onClick={() => setShowNew(false)} className="px-4 py-2 text-sm text-slate-600">Cancel</button>
                        <button
                            onClick={submit}
                            disabled={!name.trim() || !periodStart || !periodEnd || !amount}
                            className="btn-primary py-2 disabled:opacity-50"
                        >
                            Create
                        </button>
                    </div>
                </div>
            </Modal>
        </BackOfficeLayout>
    );
}
