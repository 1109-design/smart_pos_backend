import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface Customer {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    loyalty_points: string;
    credit_balance: string;
    credit_limit: string;
    is_tax_exempt: boolean;
    group: string | null;
}

interface LoyaltyEntry {
    id: string;
    points: string;
    type: string;
    note: string | null;
    created_at: string;
}

interface PurchaseEntry {
    id: string;
    sale_number: string | null;
    total: string;
    status: string;
    created_at: string;
}

interface Props {
    customer: Customer;
    loyalty_history: LoyaltyEntry[];
    purchase_history: PurchaseEntry[];
}

export default function BackOfficeCustomerShow({ customer, loyalty_history, purchase_history }: Props) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: customer.name,
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        address: customer.address ?? '',
        credit_limit: customer.credit_limit,
        is_tax_exempt: customer.is_tax_exempt,
        group: customer.group ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/office/customers/${customer.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <BackOfficeLayout>
            <Head title={customer.name} />

            <Link href="/office/customers" className="text-xs font-semibold text-slate-500 hover:text-slate-700">
                ← All customers
            </Link>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-3 mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{customer.name}</h1>
                <button onClick={() => setEditing((v) => !v)} className="text-sm font-semibold text-emerald-600 hover:text-emerald-800 flex-shrink-0">
                    {editing ? 'Cancel editing' : 'Edit'}
                </button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-1 space-y-6">
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                        {editing ? (
                            <form onSubmit={submit} className="space-y-3">
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Name</label>
                                    <input
                                        type="text"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                    {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Phone</label>
                                    <input
                                        type="text"
                                        value={form.data.phone}
                                        onChange={(e) => form.setData('phone', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Email</label>
                                    <input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Address</label>
                                    <input
                                        type="text"
                                        value={form.data.address}
                                        onChange={(e) => form.setData('address', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Credit limit</label>
                                    <input
                                        type="number" min="0" step="0.01"
                                        value={form.data.credit_limit}
                                        onChange={(e) => form.setData('credit_limit', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Group</label>
                                    <input
                                        type="text"
                                        value={form.data.group}
                                        onChange={(e) => form.setData('group', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                <label className="flex items-center gap-2 text-sm text-slate-600">
                                    <input
                                        type="checkbox"
                                        checked={form.data.is_tax_exempt}
                                        onChange={(e) => form.setData('is_tax_exempt', e.target.checked)}
                                    />
                                    Tax exempt
                                </label>
                                <button type="submit" disabled={form.processing} className="btn-primary py-2 w-full disabled:opacity-50">
                                    {form.processing ? 'Saving…' : 'Save Changes'}
                                </button>
                            </form>
                        ) : (
                            <div className="space-y-3 text-sm">
                                <div>
                                    <p className="text-xs font-semibold text-slate-400">Contact</p>
                                    <p className="text-slate-700">{customer.phone ?? '—'}</p>
                                    <p className="text-slate-700">{customer.email ?? '—'}</p>
                                    <p className="text-slate-700">{customer.address ?? '—'}</p>
                                </div>
                                <div className="flex flex-wrap gap-1.5 pt-1">
                                    {customer.group && <StatusBadge label={customer.group} variant="gray" dot={false} />}
                                    {customer.is_tax_exempt && <StatusBadge label="Tax exempt" variant="blue" dot={false} />}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 grid grid-cols-2 gap-4">
                        <div>
                            <p className="text-xs font-semibold text-slate-400">Loyalty points</p>
                            <p className="text-xl font-bold text-slate-900">{Number(customer.loyalty_points).toFixed(0)}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold text-slate-400">Credit balance</p>
                            <p className="text-xl font-bold text-slate-900">
                                {Number(customer.credit_balance).toFixed(2)}
                                {Number(customer.credit_limit) > 0 && (
                                    <span className="text-sm font-medium text-slate-400"> / {Number(customer.credit_limit).toFixed(2)}</span>
                                )}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="lg:col-span-2 space-y-6">
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="px-5 py-4 border-b border-slate-100">
                            <p className="text-sm font-semibold text-slate-800">Purchase history</p>
                        </div>
                        <div className="divide-y divide-slate-50 max-h-[260px] overflow-y-auto">
                            {purchase_history.map((sale) => (
                                <div key={sale.id} className="px-5 py-3 flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-700 truncate">{sale.sale_number ?? sale.id}</p>
                                        <p className="text-xs text-slate-400">{new Date(sale.created_at).toLocaleString()}</p>
                                    </div>
                                    <p className="text-sm font-semibold text-slate-900 flex-shrink-0">{Number(sale.total).toFixed(2)}</p>
                                </div>
                            ))}
                            {purchase_history.length === 0 && (
                                <p className="px-5 py-8 text-center text-sm text-slate-400">No purchases yet.</p>
                            )}
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                        <div className="px-5 py-4 border-b border-slate-100">
                            <p className="text-sm font-semibold text-slate-800">Loyalty activity</p>
                        </div>
                        <div className="divide-y divide-slate-50 max-h-[260px] overflow-y-auto">
                            {loyalty_history.map((entry) => (
                                <div key={entry.id} className="px-5 py-3 flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-700 capitalize">{entry.type}</p>
                                        {entry.note && <p className="text-xs text-slate-400 truncate">{entry.note}</p>}
                                        <p className="text-xs text-slate-400">{new Date(entry.created_at).toLocaleString()}</p>
                                    </div>
                                    <p className={`text-sm font-semibold flex-shrink-0 ${Number(entry.points) >= 0 ? 'text-emerald-600' : 'text-red-500'}`}>
                                        {Number(entry.points) >= 0 ? '+' : ''}{Number(entry.points).toFixed(0)}
                                    </p>
                                </div>
                            ))}
                            {loyalty_history.length === 0 && (
                                <p className="px-5 py-8 text-center text-sm text-slate-400">No loyalty activity yet.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
