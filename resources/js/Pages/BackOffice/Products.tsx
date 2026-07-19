import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';

interface ProductRow {
    id: string;
    name: string;
    item_type: 'product' | 'service';
    sku: string | null;
    barcode: string | null;
    price: string;
    cost_price: string;
    unit: string;
    track_stock: boolean;
    stock_quantity: string;
    low_stock_threshold: string;
    category_id: string | null;
    is_active: boolean;
}

interface CategoryRow {
    id: string;
    name: string;
    is_active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface Props {
    products: Paginated<ProductRow>;
    categories: CategoryRow[];
    filters: { type: string; search: string };
}

const EMPTY_FORM = {
    name: '',
    item_type: 'product' as 'product' | 'service',
    price: '',
    cost_price: '',
    sku: '',
    barcode: '',
    category_id: '',
    unit: 'piece',
    track_stock: true as boolean,
    stock_quantity: '',
    low_stock_threshold: '5',
    is_active: true as boolean,
};

export default function BackOfficeProducts({ products, categories, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<ProductRow | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [showCategories, setShowCategories] = useState(false);
    const [newCategoryName, setNewCategoryName] = useState('');
    const [renamingId, setRenamingId] = useState<string | null>(null);
    const [renameValue, setRenameValue] = useState('');

    const form = useForm({ ...EMPTY_FORM });
    const activeCategories = categories.filter((c) => c.is_active);

    const addCategory = () => {
        const name = newCategoryName.trim();
        if (!name) return;
        router.post('/office/categories', { name }, {
            preserveScroll: true,
            onSuccess: () => setNewCategoryName(''),
        });
    };

    const saveRename = (id: string) => {
        const name = renameValue.trim();
        if (!name) { setRenamingId(null); return; }
        router.put(`/office/categories/${id}`, { name }, {
            preserveScroll: true,
            onSuccess: () => setRenamingId(null),
        });
    };

    const applyFilter = (type?: string) => {
        router.get('/office/products', { search, type: type ?? filters.type }, { preserveState: true });
    };

    const openCreate = () => {
        form.setData({ ...EMPTY_FORM });
        form.clearErrors();
        setEditing(null);
        setShowForm(true);
    };

    const openEdit = (row: ProductRow) => {
        form.setData({
            name: row.name,
            item_type: row.item_type,
            price: String(row.price),
            cost_price: String(row.cost_price ?? ''),
            sku: row.sku ?? '',
            barcode: row.barcode ?? '',
            category_id: row.category_id ?? '',
            unit: row.unit,
            track_stock: row.track_stock,
            stock_quantity: String(row.stock_quantity ?? ''),
            low_stock_threshold: String(row.low_stock_threshold ?? '5'),
            is_active: row.is_active,
        });
        form.clearErrors();
        setEditing(row);
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setShowForm(false),
        };
        if (editing) {
            form.put(`/office/products/${editing.id}`, options);
        } else {
            form.post('/office/products', options);
        }
    };

    const isService = form.data.item_type === 'service';

    return (
        <BackOfficeLayout>
            <Head title="Products & Services" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Products &amp; Services</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Items created here sync to every connected till automatically — including newly paired phones.
                    </p>
                </div>
                <div className="flex gap-2 flex-shrink-0">
                    <button
                        onClick={() => setShowCategories(true)}
                        className="text-sm px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:border-emerald-300"
                    >
                        Categories
                    </button>
                    <button onClick={openCreate} className="btn-primary py-2">+ Add Item</button>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 mb-4">
                {[
                    { value: 'all', label: 'All' },
                    { value: 'product', label: 'Products' },
                    { value: 'service', label: 'Services' },
                ].map(({ value, label }) => (
                    <button
                        key={value}
                        onClick={() => applyFilter(value)}
                        className={`text-sm px-3 py-1.5 rounded-full border transition ${
                            filters.type === value
                                ? 'bg-emerald-600 border-emerald-600 text-white'
                                : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-300'
                        }`}
                    >
                        {label}
                    </button>
                ))}
                <div className="flex items-center gap-2 ml-auto">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilter()}
                        placeholder="Search name, SKU, barcode…"
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 w-56"
                    />
                    <button onClick={() => applyFilter()} className="btn-primary py-2">Search</button>
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[720px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Name</th>
                                <th className="table-th">Type</th>
                                <th className="table-th">SKU</th>
                                <th className="table-th text-right">Price</th>
                                <th className="table-th text-right">In Stock</th>
                                <th className="table-th">Status</th>
                                <th className="table-th text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {products.data.map((row) => (
                                <tr key={row.id} className={`hover:bg-slate-50/60 ${!row.is_active ? 'opacity-50' : ''}`}>
                                    <td className="table-td font-medium text-slate-900">{row.name}</td>
                                    <td className="table-td">
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                                            row.item_type === 'service' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600'
                                        }`}>
                                            {row.item_type === 'service' ? 'Service' : 'Product'}
                                        </span>
                                    </td>
                                    <td className="table-td text-slate-500">{row.sku ?? '—'}</td>
                                    <td className="table-td text-right font-semibold text-slate-900">{Number(row.price).toFixed(2)}</td>
                                    <td className="table-td text-right text-slate-600">
                                        {row.item_type === 'service' || !row.track_stock ? '—' : Number(row.stock_quantity).toFixed(0)}
                                    </td>
                                    <td className="table-td">
                                        <span className={`text-xs font-medium ${row.is_active ? 'text-emerald-600' : 'text-slate-400'}`}>
                                            {row.is_active ? 'Active' : 'Archived'}
                                        </span>
                                    </td>
                                    <td className="table-td text-right whitespace-nowrap">
                                        <button onClick={() => openEdit(row)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 mr-3">Edit</button>
                                        <button
                                            onClick={() => router.patch(`/office/products/${row.id}/toggle-active`, {}, { preserveScroll: true })}
                                            className="text-xs font-semibold text-slate-400 hover:text-slate-600"
                                        >
                                            {row.is_active ? 'Archive' : 'Restore'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {products.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-6 py-10 text-center text-sm text-slate-400">
                                        No items yet — add your first product or service above.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {products.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {products.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveState
                                    className={`text-sm px-3 py-1.5 rounded-lg ${link.active ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span key={i} className="text-sm px-3 py-1.5 text-slate-300" dangerouslySetInnerHTML={{ __html: link.label }} />
                            )
                        )}
                    </div>
                )}
            </div>

            {/* Create / Edit modal */}
            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="lg">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">{editing ? 'Edit Item' : 'New Item'}</p>

                    {/* Type selector */}
                    <div className="grid grid-cols-2 gap-3 mb-4">
                        {(['product', 'service'] as const).map((type) => (
                            <button
                                type="button"
                                key={type}
                                onClick={() => form.setData('item_type', type)}
                                className={`rounded-xl border px-4 py-3 text-left transition ${
                                    form.data.item_type === type
                                        ? 'border-emerald-600 bg-emerald-50'
                                        : 'border-slate-200 hover:border-emerald-300'
                                }`}
                            >
                                <p className="text-sm font-semibold text-slate-800">{type === 'product' ? 'Product' : 'Service'}</p>
                                <p className="text-xs text-slate-500">{type === 'product' ? 'Physical goods with stock' : 'No stock — e.g. repair, delivery'}</p>
                            </button>
                        ))}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div className="sm:col-span-2">
                            <label className="text-xs font-semibold text-slate-500">{isService ? 'Service name' : 'Product name'}</label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                placeholder={isService ? 'e.g. Phone Screen Repair' : 'e.g. Coca Cola 300ml'}
                            />
                            {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Selling price</label>
                            <input
                                type="number" step="0.01" min="0"
                                value={form.data.price}
                                onChange={(e) => form.setData('price', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {form.errors.price && <p className="text-xs text-red-500 mt-1">{form.errors.price}</p>}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Cost price</label>
                            <input
                                type="number" step="0.01" min="0"
                                value={form.data.cost_price}
                                onChange={(e) => form.setData('cost_price', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Category</label>
                            <select
                                value={form.data.category_id}
                                onChange={(e) => form.setData('category_id', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            >
                                <option value="">No category</option>
                                {activeCategories.map((c) => (
                                    <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">SKU</label>
                            <input
                                type="text"
                                value={form.data.sku}
                                onChange={(e) => form.setData('sku', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>

                        {!isService && (
                            <>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Barcode</label>
                                    <input
                                        type="text"
                                        value={form.data.barcode}
                                        onChange={(e) => form.setData('barcode', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">
                                        {editing ? 'Stock quantity (managed by tills after creation)' : 'Opening stock'}
                                    </label>
                                    <input
                                        type="number" step="1" min="0"
                                        value={form.data.stock_quantity}
                                        onChange={(e) => form.setData('stock_quantity', e.target.value)}
                                        disabled={!!editing}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:bg-slate-50 disabled:text-slate-400"
                                    />
                                </div>
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Low stock alert at</label>
                                    <input
                                        type="number" step="1" min="0"
                                        value={form.data.low_stock_threshold}
                                        onChange={(e) => form.setData('low_stock_threshold', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                            </>
                        )}

                        {isService && (
                            <div className="sm:col-span-2 rounded-xl bg-sky-50 border border-sky-100 px-4 py-3">
                                <p className="text-xs text-sky-700">
                                    Services have no stock — they can always be sold and never appear in stock counts or low-stock alerts.
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={form.processing} className="btn-primary py-2">
                            {form.processing ? 'Saving…' : editing ? 'Save Changes' : 'Create Item'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Category manager */}
            <Modal show={showCategories} onClose={() => setShowCategories(false)} maxWidth="md">
                <div className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">Categories</p>

                    <div className="flex gap-2 mb-4">
                        <input
                            type="text"
                            value={newCategoryName}
                            onChange={(e) => setNewCategoryName(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && addCategory()}
                            placeholder="New category name…"
                            className="flex-1 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                        <button onClick={addCategory} disabled={!newCategoryName.trim()} className="btn-primary py-2 disabled:opacity-50">
                            Add
                        </button>
                    </div>

                    <div className="rounded-xl border border-slate-100 divide-y divide-slate-50 max-h-80 overflow-y-auto">
                        {categories.map((c) => (
                            <div key={c.id} className={`flex items-center gap-3 px-3 py-2 ${!c.is_active ? 'opacity-50' : ''}`}>
                                {renamingId === c.id ? (
                                    <input
                                        type="text"
                                        autoFocus
                                        value={renameValue}
                                        onChange={(e) => setRenameValue(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') saveRename(c.id);
                                            if (e.key === 'Escape') setRenamingId(null);
                                        }}
                                        onBlur={() => saveRename(c.id)}
                                        className="flex-1 text-sm rounded-lg border border-emerald-300 px-2 py-1 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                ) : (
                                    <span className="flex-1 text-sm text-slate-700">{c.name}</span>
                                )}
                                {!c.is_active && (
                                    <span className="text-[10px] font-semibold uppercase text-slate-400">Archived</span>
                                )}
                                <button
                                    onClick={() => { setRenamingId(c.id); setRenameValue(c.name); }}
                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                >
                                    Rename
                                </button>
                                <button
                                    onClick={() => router.patch(`/office/categories/${c.id}/toggle-active`, {}, { preserveScroll: true })}
                                    className="text-xs font-semibold text-slate-400 hover:text-slate-600"
                                >
                                    {c.is_active ? 'Archive' : 'Restore'}
                                </button>
                            </div>
                        ))}
                        {categories.length === 0 && (
                            <p className="px-3 py-6 text-center text-sm text-slate-400">No categories yet — add your first above.</p>
                        )}
                    </div>

                    <div className="mt-4 flex justify-end">
                        <button onClick={() => setShowCategories(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Done
                        </button>
                    </div>
                </div>
            </Modal>
        </BackOfficeLayout>
    );
}
