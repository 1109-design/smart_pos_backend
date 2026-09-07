import React, { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface AssetRow {
    id: string;
    name: string;
    category: string;
    asset_tag: string | null;
    purchase_date: string;
    purchase_cost: number;
    salvage_value: number;
    depreciation_method: 'none' | 'straight_line' | 'reducing_balance';
    useful_life_years: number | null;
    depreciation_rate_percent: number | null;
    status: 'active' | 'under_repair' | 'disposed' | 'written_off';
    disposed_at: string | null;
    disposal_value: number | null;
    notes: string | null;
    book_value: number;
    accumulated_depreciation: number;
}

interface Props {
    assets: AssetRow[];
    categories: string[];
    filters: { status: string };
    summary: { count: number; total_cost: number; total_book_value: number };
}

const EMPTY_FORM = {
    name: '',
    category: 'Equipment',
    asset_tag: '',
    purchase_date: new Date().toISOString().slice(0, 10),
    purchase_cost: '',
    salvage_value: '0',
    depreciation_method: 'none' as 'none' | 'straight_line' | 'reducing_balance',
    useful_life_years: '',
    depreciation_rate_percent: '',
    notes: '',
};

const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const statusVariant = (status: string): 'green' | 'amber' | 'gray' | 'red' => {
    if (status === 'active') return 'green';
    if (status === 'under_repair') return 'amber';
    if (status === 'written_off') return 'red';
    return 'gray';
};

const statusLabel = (status: string) => {
    if (status === 'under_repair') return 'Under Repair';
    if (status === 'written_off') return 'Written Off';
    return status.charAt(0).toUpperCase() + status.slice(1);
};

export default function BackOfficeAssets({ assets, categories, filters, summary }: Props) {
    const [status, setStatus] = useState(filters.status);
    const [editing, setEditing] = useState<AssetRow | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [disposing, setDisposing] = useState<AssetRow | null>(null);

    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const form = useForm({ ...EMPTY_FORM });
    const disposeForm = useForm({
        disposed_at: new Date().toISOString().slice(0, 10),
        disposal_value: '',
    });

    const filterByStatus = (value: string) => {
        setStatus(value);
        router.get('/office/assets', { status: value }, { preserveState: true });
    };

    const openCreate = () => {
        form.setData({ ...EMPTY_FORM });
        form.clearErrors();
        setEditing(null);
        setShowForm(true);
    };

    const openEdit = (asset: AssetRow) => {
        form.setData({
            name: asset.name,
            category: asset.category,
            asset_tag: asset.asset_tag ?? '',
            purchase_date: asset.purchase_date,
            purchase_cost: String(asset.purchase_cost),
            salvage_value: String(asset.salvage_value),
            depreciation_method: asset.depreciation_method,
            useful_life_years: asset.useful_life_years ? String(asset.useful_life_years) : '',
            depreciation_rate_percent: asset.depreciation_rate_percent ? String(asset.depreciation_rate_percent) : '',
            notes: asset.notes ?? '',
        });
        form.clearErrors();
        setEditing(asset);
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        if (editing) {
            form.put(`/office/assets/${editing.id}`, options);
        } else {
            form.post('/office/assets', options);
        }
    };

    const openDispose = (asset: AssetRow) => {
        disposeForm.setData({
            disposed_at: new Date().toISOString().slice(0, 10),
            disposal_value: String(asset.book_value),
        });
        disposeForm.clearErrors();
        setDisposing(asset);
    };

    const submitDispose = (e: React.FormEvent) => {
        e.preventDefault();
        if (!disposing) return;
        disposeForm.post(`/office/assets/${disposing.id}/dispose`, {
            preserveScroll: true,
            onSuccess: () => setDisposing(null),
        });
    };

    const remove = (asset: AssetRow) => {
        if (!confirm(`Remove "${asset.name}" from the register?`)) return;
        router.delete(`/office/assets/${asset.id}`, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Business Assets" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Business Assets</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Equipment, furniture, vehicles and anything else the business owns, with an
                        automatically depreciated book value.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary py-2 flex-shrink-0">+ Add Asset</button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                    <p className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Assets on the books</p>
                    <p className="text-2xl font-bold text-slate-900 mt-1">{summary.count}</p>
                </div>
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                    <p className="text-xs font-semibold text-slate-500 uppercase tracking-wide">Total purchase cost</p>
                    <p className="text-2xl font-bold text-slate-900 mt-1">{money(summary.total_cost)}</p>
                </div>
                <div className="bg-white rounded-2xl border border-emerald-100 bg-emerald-50/40 shadow-sm p-5">
                    <p className="text-xs font-semibold text-emerald-700 uppercase tracking-wide">Total book value</p>
                    <p className="text-2xl font-bold text-emerald-700 mt-1">{money(summary.total_book_value)}</p>
                </div>
            </div>

            <div className="flex gap-1 mb-4">
                {['all', 'active', 'under_repair', 'disposed'].map((s) => (
                    <button
                        key={s}
                        onClick={() => filterByStatus(s)}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-full transition-colors ${
                            status === s ? 'bg-emerald-600 text-white' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-50'
                        }`}
                    >
                        {s === 'all' ? 'All' : statusLabel(s)}
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden overflow-x-auto">
                {assets.length === 0 ? (
                    <p className="text-center text-sm text-slate-400 py-10">No assets logged yet.</p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th className="text-left font-semibold px-5 py-3">Asset</th>
                                <th className="text-left font-semibold px-5 py-3">Category</th>
                                <th className="text-left font-semibold px-5 py-3">Purchased</th>
                                <th className="text-right font-semibold px-5 py-3">Cost</th>
                                <th className="text-right font-semibold px-5 py-3">Book Value</th>
                                <th className="text-left font-semibold px-5 py-3">Status</th>
                                <th className="text-right font-semibold px-5 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {assets.map((asset) => (
                                <tr key={asset.id}>
                                    <td className="px-5 py-3">
                                        <p className="font-medium text-slate-800">{asset.name}</p>
                                        {asset.asset_tag && <p className="text-xs text-slate-400">#{asset.asset_tag}</p>}
                                    </td>
                                    <td className="px-5 py-3 text-slate-600">{asset.category}</td>
                                    <td className="px-5 py-3 text-slate-500">{asset.purchase_date}</td>
                                    <td className="px-5 py-3 text-right text-slate-600">{money(asset.purchase_cost)}</td>
                                    <td className="px-5 py-3 text-right font-semibold text-slate-800">
                                        {money(asset.status === 'disposed' ? (asset.disposal_value ?? asset.book_value) : asset.book_value)}
                                    </td>
                                    <td className="px-5 py-3">
                                        <StatusBadge label={statusLabel(asset.status)} variant={statusVariant(asset.status)} />
                                    </td>
                                    <td className="px-5 py-3">
                                        <div className="flex items-center justify-end gap-3">
                                            <button onClick={() => openEdit(asset)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">
                                                Edit
                                            </button>
                                            {asset.status !== 'disposed' && (
                                                <button onClick={() => openDispose(asset)} className="text-xs font-semibold text-slate-500 hover:text-slate-700">
                                                    Dispose
                                                </button>
                                            )}
                                            <button onClick={() => remove(asset)} className="text-xs font-semibold text-slate-400 hover:text-red-600">
                                                Remove
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="md">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">{editing ? 'Edit Asset' : 'Add Asset'}</p>

                    <div className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Asset name</label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Category</label>
                                <select
                                    value={form.data.category}
                                    onChange={(e) => form.setData('category', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    {categories.map((c) => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Asset tag (optional)</label>
                                <input
                                    type="text"
                                    value={form.data.asset_tag}
                                    onChange={(e) => form.setData('asset_tag', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Purchase date</label>
                                <input
                                    type="date"
                                    value={form.data.purchase_date}
                                    onChange={(e) => form.setData('purchase_date', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Purchase cost</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    value={form.data.purchase_cost}
                                    onChange={(e) => form.setData('purchase_cost', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                                {form.errors.purchase_cost && <p className="text-xs text-red-500 mt-1">{form.errors.purchase_cost}</p>}
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Salvage value</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    value={form.data.salvage_value}
                                    onChange={(e) => form.setData('salvage_value', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Depreciation</label>
                            <div className="mt-1 flex gap-2">
                                {(['none', 'straight_line', 'reducing_balance'] as const).map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        onClick={() => form.setData('depreciation_method', m)}
                                        className={`flex-1 text-xs font-semibold py-2 rounded-xl border transition-colors ${
                                            form.data.depreciation_method === m
                                                ? 'bg-emerald-600 border-emerald-600 text-white'
                                                : 'border-slate-200 text-slate-500 hover:bg-slate-50'
                                        }`}
                                    >
                                        {m === 'none' ? 'None' : m === 'straight_line' ? 'Straight-line' : 'Reducing balance'}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {form.data.depreciation_method === 'straight_line' && (
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Useful life (years)</label>
                                <input
                                    type="number"
                                    value={form.data.useful_life_years}
                                    onChange={(e) => form.setData('useful_life_years', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                        )}
                        {form.data.depreciation_method === 'reducing_balance' && (
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Annual depreciation rate (%)</label>
                                <input
                                    type="number"
                                    step="0.1"
                                    value={form.data.depreciation_rate_percent}
                                    onChange={(e) => form.setData('depreciation_rate_percent', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                        )}

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Notes (optional)</label>
                            <textarea
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                rows={2}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={form.processing} className="btn-primary py-2 disabled:opacity-50">
                            {form.processing ? 'Saving…' : editing ? 'Save Changes' : 'Add Asset'}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={disposing !== null} onClose={() => setDisposing(null)} maxWidth="sm">
                <form onSubmit={submitDispose} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-1">Dispose Asset</p>
                    <p className="text-xs text-slate-500 mb-4">
                        Freezes {disposing?.name}'s value and marks it as no longer owned by the business.
                    </p>

                    <div className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Disposal date</label>
                            <input
                                type="date"
                                value={disposeForm.data.disposed_at}
                                onChange={(e) => disposeForm.setData('disposed_at', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Disposal / sale value</label>
                            <input
                                type="number"
                                step="0.01"
                                value={disposeForm.data.disposal_value}
                                onChange={(e) => disposeForm.setData('disposal_value', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setDisposing(null)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={disposeForm.processing} className="bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-4 py-2 rounded-xl disabled:opacity-50">
                            {disposeForm.processing ? 'Saving…' : 'Confirm Disposal'}
                        </button>
                    </div>
                </form>
            </Modal>
        </BackOfficeLayout>
    );
}
