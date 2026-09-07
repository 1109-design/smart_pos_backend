import React, { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface RequestRow {
    id: string;
    product_name: string;
    note: string | null;
    status: 'open' | 'fulfilled';
    requested_by_user_id: string | null;
    requested_by?: { id: string; name: string } | null;
    created_at: string;
}

interface TallyRow {
    product_name: string;
    total: number;
    open_count: number;
    last_asked_at: string;
}

interface Props {
    requests: RequestRow[];
    tally: TallyRow[];
}

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

export default function BackOfficeProductRequests({ requests, tally }: Props) {
    const [tab, setTab] = useState<'tally' | 'recent'>('tally');
    const [showForm, setShowForm] = useState(false);
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const form = useForm({ product_name: '', note: '' });

    const openCreate = () => {
        form.setData({ product_name: '', note: '' });
        form.clearErrors();
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/product-requests', {
            preserveScroll: true,
            onSuccess: () => setShowForm(false),
        });
    };

    const toggleStatus = (row: RequestRow) => {
        router.patch(`/office/product-requests/${row.id}/toggle-status`, {}, { preserveScroll: true });
    };

    const remove = (row: RequestRow) => {
        if (!confirm(`Remove this note ("${row.product_name}")?`)) return;
        router.delete(`/office/product-requests/${row.id}`, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Notes" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Notes: Requested Products</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        What customers keep asking for. Staff log these from the till whenever they don't have
                        something in stock — use this to spot what's worth adding to the catalogue.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary py-2 flex-shrink-0">+ Log a Request</button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="flex gap-1 mb-4 border-b border-slate-200">
                {(['tally', 'recent'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => setTab(t)}
                        className={`px-4 py-2 text-sm font-semibold border-b-2 -mb-px transition-colors ${
                            tab === t
                                ? 'border-emerald-600 text-emerald-700'
                                : 'border-transparent text-slate-400 hover:text-slate-600'
                        }`}
                    >
                        {t === 'tally' ? 'Most Requested' : 'Recent Asks'}
                    </button>
                ))}
            </div>

            {tab === 'tally' && (
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    {tally.length === 0 ? (
                        <p className="text-center text-sm text-slate-400 py-10">No requests logged yet.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                                <tr>
                                    <th className="text-left font-semibold px-5 py-3">Product</th>
                                    <th className="text-left font-semibold px-5 py-3">Times Asked</th>
                                    <th className="text-left font-semibold px-5 py-3">Still Open</th>
                                    <th className="text-left font-semibold px-5 py-3">Last Asked</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {tally.map((row) => (
                                    <tr key={row.product_name}>
                                        <td className="px-5 py-3 font-medium text-slate-800">{row.product_name}</td>
                                        <td className="px-5 py-3 text-slate-600">{row.total}</td>
                                        <td className="px-5 py-3">
                                            {row.open_count > 0 ? (
                                                <StatusBadge label={`${row.open_count} open`} variant="amber" />
                                            ) : (
                                                <StatusBadge label="All fulfilled" variant="green" />
                                            )}
                                        </td>
                                        <td className="px-5 py-3 text-slate-500">{formatDate(row.last_asked_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            )}

            {tab === 'recent' && (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {requests.map((row) => (
                        <div key={row.id} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                            <div className="flex items-start justify-between gap-2 mb-2">
                                <p className={`text-sm font-semibold text-slate-900 ${row.status === 'fulfilled' ? 'line-through text-slate-400' : ''}`}>
                                    {row.product_name}
                                </p>
                                <StatusBadge
                                    label={row.status === 'fulfilled' ? 'Fulfilled' : 'Open'}
                                    variant={row.status === 'fulfilled' ? 'green' : 'amber'}
                                />
                            </div>
                            {row.note && <p className="text-xs text-slate-500 mb-3">{row.note}</p>}
                            <p className="text-xs text-slate-400 mb-3">
                                {row.requested_by?.name ?? 'Staff'} · {formatDateTime(row.created_at)}
                            </p>
                            <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                                <button
                                    onClick={() => toggleStatus(row)}
                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                >
                                    Mark {row.status === 'open' ? 'fulfilled' : 'open'}
                                </button>
                                <button
                                    onClick={() => remove(row)}
                                    className="text-xs font-semibold text-slate-400 hover:text-red-600"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}

                    {requests.length === 0 && (
                        <p className="col-span-full text-center text-sm text-slate-400 py-10">No requests logged yet.</p>
                    )}
                </div>
            )}

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="md">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">Log a Requested Product</p>

                    <div className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Product name</label>
                            <input
                                type="text"
                                value={form.data.product_name}
                                onChange={(e) => form.setData('product_name', e.target.value)}
                                placeholder="What did the customer ask for?"
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {form.errors.product_name && (
                                <p className="text-xs text-red-500 mt-1">{form.errors.product_name}</p>
                            )}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Note (optional)</label>
                            <textarea
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                rows={2}
                                placeholder="e.g. size, brand, how many asked"
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={form.processing} className="btn-primary py-2 disabled:opacity-50">
                            {form.processing ? 'Saving…' : 'Save'}
                        </button>
                    </div>
                </form>
            </Modal>
        </BackOfficeLayout>
    );
}
