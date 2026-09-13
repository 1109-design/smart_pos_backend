import React, { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface BankAccountRow {
    id: string;
    name: string;
    account_number: string | null;
    branch: string | null;
    currency_code: string;
    is_active: boolean;
}

interface Props {
    accounts: BankAccountRow[];
}

export default function BankAccounts({ accounts }: Props) {
    const [showForm, setShowForm] = useState(false);
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const form = useForm({ name: '', account_number: '', branch: '', currency_code: 'USD' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/bank-accounts', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setShowForm(false);
            },
        });
    };

    const deactivate = (id: string) => {
        if (!confirm('Deactivate this bank account? Its history stays intact.')) return;
        form.post(`/office/bank-accounts/${id}/deactivate`, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Bank Accounts" />

            <div className="mb-6 flex items-start justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Bank Accounts</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Named bank accounts selectable wherever a payment is made by bank transfer — each has its own
                        cash book.
                    </p>
                </div>
                <button onClick={() => setShowForm((v) => !v)} className="btn-primary py-2 px-4">
                    {showForm ? 'Cancel' : 'Add Bank Account'}
                </button>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            {showForm && (
                <form onSubmit={submit} className="mb-6 bg-white rounded-2xl border border-slate-100 shadow-sm p-5 grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div className="sm:col-span-2">
                        <label className="text-xs font-semibold text-slate-500">Account name</label>
                        <input
                            type="text" required placeholder="e.g. CBZ Main Account"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                        {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-500">Account number</label>
                        <input
                            type="text"
                            value={form.data.account_number}
                            onChange={(e) => form.setData('account_number', e.target.value)}
                            className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="text-xs font-semibold text-slate-500">Branch</label>
                        <input
                            type="text"
                            value={form.data.branch}
                            onChange={(e) => form.setData('branch', e.target.value)}
                            className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div className="sm:col-span-4">
                        <button type="submit" disabled={form.processing} className="btn-primary py-2 px-6 disabled:opacity-50">
                            {form.processing ? 'Saving…' : 'Add Bank Account'}
                        </button>
                    </div>
                </form>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                            <th className="px-5 py-2.5">Name</th>
                            <th className="px-5 py-2.5">Account Number</th>
                            <th className="px-5 py-2.5">Branch</th>
                            <th className="px-5 py-2.5">Currency</th>
                            <th className="px-5 py-2.5">Status</th>
                            <th className="px-5 py-2.5" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {accounts.map((a) => (
                            <tr key={a.id}>
                                <td className="px-5 py-3 text-slate-800 font-medium">
                                    <Link href={`/office/bank-accounts/${a.id}/cash-book`} className="hover:underline">
                                        {a.name}
                                    </Link>
                                </td>
                                <td className="px-5 py-3 text-slate-500">{a.account_number ?? '—'}</td>
                                <td className="px-5 py-3 text-slate-500">{a.branch ?? '—'}</td>
                                <td className="px-5 py-3 text-slate-500">{a.currency_code}</td>
                                <td className="px-5 py-3">
                                    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${a.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                                        {a.is_active ? 'Active' : 'Inactive'}
                                    </span>
                                </td>
                                <td className="px-5 py-3 text-right">
                                    {a.is_active && (
                                        <button onClick={() => deactivate(a.id)} className="text-xs font-semibold text-red-500 hover:underline">
                                            Deactivate
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {accounts.length === 0 && (
                    <p className="px-5 py-10 text-center text-sm text-slate-400">No bank accounts yet.</p>
                )}
            </div>
        </BackOfficeLayout>
    );
}
