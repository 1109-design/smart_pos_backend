import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';

interface CatalogItem {
    id: string;
    name: string;
    item_type: 'product' | 'service';
    price: string;
}

interface BundleItemRow {
    id: string;
    product_id: string;
    quantity: number;
    name: string;
    item_type: 'product' | 'service';
    price: number;
}

interface BundleRow {
    id: string;
    name: string;
    description: string | null;
    is_active: boolean;
    items: BundleItemRow[];
}

interface Props {
    bundles: BundleRow[];
    catalog: CatalogItem[];
}

interface FormItem {
    product_id: string;
    quantity: number;
}

export default function BackOfficeBundles({ bundles, catalog }: Props) {
    const [editing, setEditing] = useState<BundleRow | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [picker, setPicker] = useState('');

    const form = useForm<{ name: string; description: string; items: FormItem[] }>({
        name: '',
        description: '',
        items: [],
    });

    const catalogById = Object.fromEntries(catalog.map((c) => [c.id, c]));

    const openCreate = () => {
        form.setData({ name: '', description: '', items: [] });
        form.clearErrors();
        setEditing(null);
        setShowForm(true);
    };

    const openEdit = (bundle: BundleRow) => {
        form.setData({
            name: bundle.name,
            description: bundle.description ?? '',
            items: bundle.items.map((i) => ({ product_id: i.product_id, quantity: i.quantity })),
        });
        form.clearErrors();
        setEditing(bundle);
        setShowForm(true);
    };

    const addItem = (productId: string) => {
        if (!productId) return;
        if (form.data.items.some((i) => i.product_id === productId)) return;
        form.setData('items', [...form.data.items, { product_id: productId, quantity: 1 }]);
        setPicker('');
    };

    const setQuantity = (productId: string, quantity: number) => {
        form.setData('items', form.data.items.map((i) => (i.product_id === productId ? { ...i, quantity } : i)));
    };

    const removeItem = (productId: string) => {
        form.setData('items', form.data.items.filter((i) => i.product_id !== productId));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        if (editing) {
            form.put(`/office/combos/${editing.id}`, options);
        } else {
            form.post('/office/combos', options);
        }
    };

    const comboTotal = form.data.items.reduce(
        (sum, item) => sum + (Number(catalogById[item.product_id]?.price ?? 0) * item.quantity),
        0
    );

    return (
        <BackOfficeLayout>
            <Head title="Combos" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Combos</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        One tap at the till fills in every line — parts and labour together, so fitting is never forgotten.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary py-2 flex-shrink-0">+ New Combo</button>
            </div>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {bundles.map((bundle) => (
                    <div key={bundle.id} className={`bg-white rounded-2xl border border-slate-100 shadow-sm p-5 ${!bundle.is_active ? 'opacity-50' : ''}`}>
                        <div className="flex items-start justify-between gap-2 mb-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">{bundle.name}</p>
                                {bundle.description && <p className="text-xs text-slate-500 mt-0.5">{bundle.description}</p>}
                            </div>
                            <span className={`text-xs font-medium flex-shrink-0 ${bundle.is_active ? 'text-emerald-600' : 'text-slate-400'}`}>
                                {bundle.is_active ? 'Active' : 'Archived'}
                            </span>
                        </div>
                        <ul className="space-y-1.5 mb-4">
                            {bundle.items.map((item) => (
                                <li key={item.id} className="flex items-center justify-between gap-2 text-sm">
                                    <span className="text-slate-600 min-w-0 truncate">
                                        {item.quantity !== 1 && <span className="font-semibold">{item.quantity}× </span>}
                                        {item.name}
                                        <span className={`ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                            item.item_type === 'service' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-500'
                                        }`}>
                                            {item.item_type === 'service' ? 'Service' : 'Product'}
                                        </span>
                                    </span>
                                    <span className="text-slate-500 tabular-nums flex-shrink-0">{(item.price * item.quantity).toFixed(2)}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                            <p className="text-sm font-bold text-slate-900">
                                {bundle.items.reduce((s, i) => s + i.price * i.quantity, 0).toFixed(2)}
                            </p>
                            <div>
                                <button onClick={() => openEdit(bundle)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 mr-3">Edit</button>
                                <button
                                    onClick={() => router.patch(`/office/combos/${bundle.id}/toggle-active`, {}, { preserveScroll: true })}
                                    className="text-xs font-semibold text-slate-400 hover:text-slate-600"
                                >
                                    {bundle.is_active ? 'Archive' : 'Restore'}
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
                {bundles.length === 0 && (
                    <div className="md:col-span-2 xl:col-span-3 bg-white rounded-2xl border border-slate-100 shadow-sm px-6 py-12 text-center text-sm text-slate-400">
                        No combos yet. Create one — e.g. "Roof Carrier + Fitting" — and it appears on every till.
                    </div>
                )}
            </div>

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="lg">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">{editing ? 'Edit Combo' : 'New Combo'}</p>

                    <div className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Combo name</label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Roof Carrier + Fitting"
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Description (optional)</label>
                            <input
                                type="text"
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Add item</label>
                            <select
                                value={picker}
                                onChange={(e) => addItem(e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            >
                                <option value="">Choose a product or service…</option>
                                {catalog
                                    .filter((c) => !form.data.items.some((i) => i.product_id === c.id))
                                    .map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name} ({c.item_type === 'service' ? 'Service' : 'Product'} — {Number(c.price).toFixed(2)})
                                        </option>
                                    ))}
                            </select>
                            {form.errors.items && <p className="text-xs text-red-500 mt-1">{form.errors.items}</p>}
                        </div>

                        {form.data.items.length > 0 && (
                            <div className="rounded-xl border border-slate-100 divide-y divide-slate-50">
                                {form.data.items.map((item) => {
                                    const info = catalogById[item.product_id];
                                    return (
                                        <div key={item.product_id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
                                            <span className="flex-1 min-w-[8rem] text-sm text-slate-700 truncate">{info?.name ?? item.product_id}</span>
                                            <input
                                                type="number" min="0.01" step="0.01"
                                                value={item.quantity}
                                                onChange={(e) => setQuantity(item.product_id, Number(e.target.value))}
                                                className="w-16 text-sm rounded-lg border border-slate-200 px-2 py-1 text-right focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                            />
                                            <span className="w-16 text-right text-sm text-slate-500 tabular-nums">
                                                {(Number(info?.price ?? 0) * item.quantity).toFixed(2)}
                                            </span>
                                            <button type="button" onClick={() => removeItem(item.product_id)} className="text-xs font-semibold text-red-400 hover:text-red-600">
                                                Remove
                                            </button>
                                        </div>
                                    );
                                })}
                                <div className="flex items-center justify-between px-3 py-2 bg-slate-50 rounded-b-xl">
                                    <span className="text-xs font-semibold text-slate-500">Combo total at current prices</span>
                                    <span className="text-sm font-bold text-slate-900 tabular-nums">{comboTotal.toFixed(2)}</span>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={form.processing || form.data.items.length === 0} className="btn-primary py-2 disabled:opacity-50">
                            {form.processing ? 'Saving…' : editing ? 'Save Changes' : 'Create Combo'}
                        </button>
                    </div>
                </form>
            </Modal>
        </BackOfficeLayout>
    );
}
