import React, { useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';

interface StockLocationRow {
    location_id: string;
    location_name: string;
    quantity: number;
    reserved_quantity: number;
}

interface ContainerLinkRow {
    id: string;
    container_product_id: string;
    quantity_per_unit: number;
}

interface ProductRow {
    id: string;
    name: string;
    item_type: 'product' | 'service' | 'container';
    sku: string | null;
    barcode: string | null;
    price: string;
    cost_price: string;
    min_price: string | null;
    discount_percent: string | null;
    deposit_amount: string | null;
    expiry_date: string | null;
    unit: string;
    track_stock: boolean;
    stock_quantity: string;
    low_stock_threshold: string;
    category_id: string | null;
    is_active: boolean;
    synced_devices: number | null;
    total_devices: number;
    stock_by_location?: StockLocationRow[];
    container_links?: ContainerLinkRow[];
}

interface ContainerOption {
    id: string;
    name: string;
    deposit_amount: string | null;
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

interface LocationOption {
    id: string;
    name: string;
    type: 'shop' | 'warehouse';
}

interface Props {
    products: Paginated<ProductRow>;
    categories: CategoryRow[];
    locations: LocationOption[];
    containers: ContainerOption[];
    default_location_id: string;
    filters: { type: string; search: string };
}

const EMPTY_FORM = {
    name: '',
    item_type: 'product' as 'product' | 'service' | 'container',
    price: '',
    cost_price: '',
    min_price: '',
    discount_percent: '',
    deposit_amount: '',
    expiry_date: '',
    sku: '',
    barcode: '',
    category_id: '',
    unit: 'piece',
    track_stock: true as boolean,
    stock_quantity: '',
    low_stock_threshold: '5',
    location_id: '',
    is_active: true as boolean,
    container_links: [] as { container_product_id: string; quantity_per_unit: string }[],
};

function SyncBadge({ synced, total }: { synced: number | null; total: number }) {
    if (total === 0) {
        return <span className="text-xs text-slate-400" title="No devices have connected in the last 14 days">No active devices</span>;
    }

    if (synced === null) {
        return <span className="text-xs text-slate-400">—</span>;
    }

    const fullySynced = synced === total;

    return (
        <span
            className={`inline-flex items-center gap-1.5 text-xs font-medium ${fullySynced ? 'text-emerald-600' : 'text-amber-600'}`}
            title="Counts devices seen in the last 14 days — long-idle devices aren't included"
        >
            <span className={`w-1.5 h-1.5 rounded-full ${fullySynced ? 'bg-emerald-500' : 'bg-amber-500'}`} />
            {synced}/{total} synced
        </span>
    );
}

function StockByLocation({ rows, onEdit }: { rows: StockLocationRow[]; onEdit: (row: StockLocationRow) => void }) {
    return (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
            {rows.map((r) => (
                <div key={r.location_id} className="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2">
                    <div className="flex items-start justify-between gap-2">
                        <p className="text-xs text-slate-400 truncate">{r.location_name}</p>
                        <button
                            onClick={() => onEdit(r)}
                            className="text-[11px] font-semibold text-emerald-600 hover:text-emerald-800 flex-shrink-0"
                            title="Set exact balance at this location"
                        >
                            Set balance
                        </button>
                    </div>
                    <p className="text-sm font-semibold text-slate-800">
                        {r.quantity.toFixed(0)}
                        {r.reserved_quantity > 0 && (
                            <span className="text-xs font-normal text-amber-600 ml-1">({r.reserved_quantity.toFixed(0)} reserved)</span>
                        )}
                    </p>
                </div>
            ))}
        </div>
    );
}

function typeBadgeClass(itemType: ProductRow['item_type']): string {
    if (itemType === 'service') return 'bg-sky-50 text-sky-700';
    if (itemType === 'container') return 'bg-amber-50 text-amber-700';
    return 'bg-slate-100 text-slate-600';
}

function typeLabel(itemType: ProductRow['item_type']): string {
    if (itemType === 'service') return 'Service';
    if (itemType === 'container') return 'Container';
    return 'Product';
}

export default function BackOfficeProducts({ products, categories, locations, containers, default_location_id, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [expandedId, setExpandedId] = useState<string | null>(null);
    const [editing, setEditing] = useState<ProductRow | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [showCategories, setShowCategories] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const [newCategoryName, setNewCategoryName] = useState('');
    const [renamingId, setRenamingId] = useState<string | null>(null);
    const [renameValue, setRenameValue] = useState('');
    const [balanceEdit, setBalanceEdit] = useState<{ productId: string; productName: string; locationName: string } | null>(null);
    const importFileInput = useRef<HTMLInputElement>(null);

    const { flash } = usePage().props as unknown as {
        flash: { success: string | null; import_errors: string[] | null };
    };

    const form = useForm({ ...EMPTY_FORM });
    const importForm = useForm<{ file: File | null; location_id: string }>({ file: null, location_id: default_location_id });
    const balanceForm = useForm<{ location_id: string; quantity: string }>({ location_id: '', quantity: '' });
    const activeCategories = categories.filter((c) => c.is_active);

    const openBalanceEdit = (productId: string, productName: string, row: StockLocationRow) => {
        balanceForm.setData({ location_id: row.location_id, quantity: String(row.quantity) });
        balanceForm.clearErrors();
        setBalanceEdit({ productId, productName, locationName: row.location_name });
    };

    const submitBalance = (e: React.FormEvent) => {
        e.preventDefault();
        if (!balanceEdit) return;
        balanceForm.post(`/office/products/${balanceEdit.productId}/opening-balance`, {
            preserveScroll: true,
            onSuccess: () => setBalanceEdit(null),
        });
    };

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
        form.setData({ ...EMPTY_FORM, location_id: default_location_id });
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
            min_price: row.min_price ?? '',
            discount_percent: row.discount_percent ?? '',
            deposit_amount: row.deposit_amount ?? '',
            expiry_date: row.expiry_date ? row.expiry_date.slice(0, 10) : '',
            sku: row.sku ?? '',
            barcode: row.barcode ?? '',
            category_id: row.category_id ?? '',
            unit: row.unit,
            track_stock: row.track_stock,
            stock_quantity: String(row.stock_quantity ?? ''),
            low_stock_threshold: String(row.low_stock_threshold ?? '5'),
            location_id: '',
            is_active: row.is_active,
            container_links: (row.container_links ?? []).map((link) => ({
                container_product_id: link.container_product_id,
                quantity_per_unit: String(link.quantity_per_unit),
            })),
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
    const isContainer = form.data.item_type === 'container';
    const activeContainers = containers;

    const addContainerLink = () => {
        if (activeContainers.length === 0) return;
        const used = new Set(form.data.container_links.map((l) => l.container_product_id));
        const next = activeContainers.find((c) => !used.has(c.id)) ?? activeContainers[0];
        form.setData('container_links', [
            ...form.data.container_links,
            { container_product_id: next.id, quantity_per_unit: '1' },
        ]);
    };

    const updateContainerLink = (index: number, patch: Partial<{ container_product_id: string; quantity_per_unit: string }>) => {
        form.setData(
            'container_links',
            form.data.container_links.map((link, i) => (i === index ? { ...link, ...patch } : link))
        );
    };

    const removeContainerLink = (index: number) => {
        form.setData('container_links', form.data.container_links.filter((_, i) => i !== index));
    };

    const submitImport = (e: React.FormEvent) => {
        e.preventDefault();
        if (!importForm.data.file) return;
        importForm.post('/office/products/import', {
            preserveScroll: true,
            onSuccess: () => {
                importForm.setData('file', null);
                if (importFileInput.current) importFileInput.current.value = '';
            },
        });
    };

    const closeImport = () => {
        setShowImport(false);
        importForm.clearErrors();
        importForm.setData('file', null);
        if (importFileInput.current) importFileInput.current.value = '';
    };

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
                <div className="flex flex-wrap gap-2 flex-shrink-0">
                    <button
                        onClick={() => setShowCategories(true)}
                        className="text-sm px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:border-emerald-300"
                    >
                        Categories
                    </button>
                    <button
                        onClick={() => setShowImport(true)}
                        className="text-sm px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:border-emerald-300"
                    >
                        Import CSV
                    </button>
                    <button onClick={openCreate} className="btn-primary py-2">+ Add Item</button>
                </div>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            {flash?.import_errors && flash.import_errors.length > 0 && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                    <p className="text-sm font-semibold text-amber-800 mb-1">Rows that need attention</p>
                    <ul className="text-xs text-amber-700 space-y-0.5 list-disc list-inside">
                        {flash.import_errors.map((message, i) => (
                            <li key={i}>{message}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2 mb-4">
                {[
                    { value: 'all', label: 'All' },
                    { value: 'product', label: 'Products' },
                    { value: 'service', label: 'Services' },
                    { value: 'container', label: 'Containers' },
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
                <div className="flex items-center gap-2 w-full sm:w-auto sm:ml-auto">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilter()}
                        placeholder="Search name, SKU, barcode…"
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 flex-1 min-w-0 sm:w-56 sm:flex-none"
                    />
                    <button onClick={() => applyFilter()} className="btn-primary py-2 flex-shrink-0">Search</button>
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                {/* Mobile: card list */}
                <div className="md:hidden divide-y divide-slate-50">
                    {products.data.map((row) => {
                        const hasBreakdown = locations.length > 1 && (row.stock_by_location?.length ?? 0) > 0;
                        const expanded = expandedId === row.id;
                        return (
                        <div key={row.id} className={`p-4 ${!row.is_active ? 'opacity-50' : ''}`}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-slate-900 truncate">{row.name}</p>
                                    <p className="text-xs text-slate-400 mt-0.5">{row.sku ?? 'No SKU'}</p>
                                </div>
                                <span className="text-base font-bold text-slate-900 flex-shrink-0">{Number(row.price).toFixed(2)}</span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2 mt-2.5">
                                <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${typeBadgeClass(row.item_type)}`}>
                                    {typeLabel(row.item_type)}
                                </span>
                                <span className={`text-xs font-medium ${row.is_active ? 'text-emerald-600' : 'text-slate-400'}`}>
                                    {row.is_active ? 'Active' : 'Archived'}
                                </span>
                                {row.item_type !== 'service' && row.track_stock && (
                                    hasBreakdown ? (
                                        <button
                                            onClick={() => setExpandedId(expanded ? null : row.id)}
                                            className="text-xs font-semibold text-slate-600 hover:text-emerald-700 underline decoration-dotted"
                                        >
                                            {Number(row.stock_quantity).toFixed(0)} in stock · by location
                                        </button>
                                    ) : (
                                        <span className="text-xs text-slate-500">{Number(row.stock_quantity).toFixed(0)} in stock</span>
                                    )
                                )}
                            </div>
                            {hasBreakdown && expanded && (
                                <div className="mt-3">
                                    <StockByLocation rows={row.stock_by_location!} onEdit={(loc) => openBalanceEdit(row.id, row.name, loc)} />
                                </div>
                            )}
                            <div className="mt-2">
                                <SyncBadge synced={row.synced_devices} total={row.total_devices} />
                            </div>
                            <div className="flex items-center gap-3 mt-3">
                                <button onClick={() => openEdit(row)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">Edit</button>
                                <button
                                    onClick={() => router.patch(`/office/products/${row.id}/toggle-active`, {}, { preserveScroll: true })}
                                    className="text-xs font-semibold text-slate-400 hover:text-slate-600"
                                >
                                    {row.is_active ? 'Archive' : 'Restore'}
                                </button>
                            </div>
                        </div>
                        );
                    })}
                    {products.data.length === 0 && (
                        <p className="px-4 py-10 text-center text-sm text-slate-400">
                            No items yet — add your first product or service above.
                        </p>
                    )}
                </div>

                {/* Desktop: table */}
                <div className="hidden md:block overflow-x-auto">
                    <table className="w-full text-sm min-w-[720px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Name</th>
                                <th className="table-th">Type</th>
                                <th className="table-th">SKU</th>
                                <th className="table-th text-right">Price</th>
                                <th className="table-th text-right">In Stock</th>
                                <th className="table-th">Status</th>
                                <th className="table-th">Synced</th>
                                <th className="table-th text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {products.data.map((row) => {
                                const hasBreakdown = locations.length > 1 && (row.stock_by_location?.length ?? 0) > 0;
                                const expanded = expandedId === row.id;
                                return (
                                <React.Fragment key={row.id}>
                                <tr className={`hover:bg-slate-50/60 ${!row.is_active ? 'opacity-50' : ''}`}>
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
                                        {row.item_type === 'service' || !row.track_stock ? (
                                            '—'
                                        ) : hasBreakdown ? (
                                            <button
                                                onClick={() => setExpandedId(expanded ? null : row.id)}
                                                className="inline-flex items-center gap-1 font-semibold text-slate-700 hover:text-emerald-700"
                                            >
                                                {Number(row.stock_quantity).toFixed(0)}
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className={`w-3.5 h-3.5 transition-transform ${expanded ? 'rotate-180' : ''}`}>
                                                    <path fillRule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
                                                </svg>
                                            </button>
                                        ) : (
                                            Number(row.stock_quantity).toFixed(0)
                                        )}
                                    </td>
                                    <td className="table-td">
                                        <span className={`text-xs font-medium ${row.is_active ? 'text-emerald-600' : 'text-slate-400'}`}>
                                            {row.is_active ? 'Active' : 'Archived'}
                                        </span>
                                    </td>
                                    <td className="table-td">
                                        <SyncBadge synced={row.synced_devices} total={row.total_devices} />
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
                                {hasBreakdown && expanded && (
                                    <tr>
                                        <td colSpan={8} className="bg-slate-50/60 px-6 py-3 border-t border-slate-100">
                                            <StockByLocation rows={row.stock_by_location!} onEdit={(loc) => openBalanceEdit(row.id, row.name, loc)} />
                                        </td>
                                    </tr>
                                )}
                                </React.Fragment>
                                );
                            })}
                            {products.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-6 py-10 text-center text-sm text-slate-400">
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
                    <div className="grid grid-cols-3 gap-3 mb-4">
                        {(['product', 'service', 'container'] as const).map((type) => (
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
                                <p className="text-sm font-semibold text-slate-800">{type === 'product' ? 'Product' : type === 'service' ? 'Service' : 'Container'}</p>
                                <p className="text-xs text-slate-500">
                                    {type === 'product' ? 'Physical goods with stock' : type === 'service' ? 'No stock — e.g. repair, delivery' : 'Returnable bottle/crate + deposit'}
                                </p>
                            </button>
                        ))}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div className="sm:col-span-2">
                            <label className="text-xs font-semibold text-slate-500">{isContainer ? 'Container name' : isService ? 'Service name' : 'Product name'}</label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                placeholder={isContainer ? 'e.g. Quart Bottle' : isService ? 'e.g. Phone Screen Repair' : 'e.g. Coca Cola 300ml'}
                            />
                            {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                        </div>

                        {isContainer ? (
                            <div className="sm:col-span-2 rounded-xl bg-amber-50 border border-amber-100 px-4 py-3">
                                <p className="text-xs text-amber-800 mb-3">
                                    Charged when this container leaves the shop with a sale, refunded when the customer returns the empty.
                                </p>
                                <label className="text-xs font-semibold text-slate-500">Deposit amount</label>
                                <input
                                    type="number" step="0.01" min="0"
                                    value={form.data.deposit_amount}
                                    onChange={(e) => form.setData('deposit_amount', e.target.value)}
                                    className="mt-1 w-full sm:w-56 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                                {form.errors.deposit_amount && <p className="text-xs text-red-500 mt-1">{form.errors.deposit_amount}</p>}
                            </div>
                        ) : (
                            <>
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
                                    <label className="text-xs font-semibold text-slate-500">Negotiation floor</label>
                                    <input
                                        type="number" step="0.01" min="0"
                                        value={form.data.min_price}
                                        onChange={(e) => form.setData('min_price', e.target.value)}
                                        placeholder="Minimum allowed price"
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>

                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Default discount %</label>
                                    <input
                                        type="number" step="0.01" min="0" max="100"
                                        value={form.data.discount_percent}
                                        onChange={(e) => form.setData('discount_percent', e.target.value)}
                                        placeholder="Automatic discount"
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                            </>
                        )}

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
                                <div>
                                    <label className="text-xs font-semibold text-slate-500">Expiry date</label>
                                    <input
                                        type="date"
                                        value={form.data.expiry_date}
                                        onChange={(e) => form.setData('expiry_date', e.target.value)}
                                        className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                                {!editing && locations.length > 1 && (
                                    <div>
                                        <label className="text-xs font-semibold text-slate-500">Opening stock goes to</label>
                                        <select
                                            value={form.data.location_id}
                                            onChange={(e) => form.setData('location_id', e.target.value)}
                                            className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        >
                                            {locations.map((l) => (
                                                <option key={l.id} value={l.id}>{l.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}
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

                    {form.data.item_type === 'product' && activeContainers.length > 0 && (
                        <div className="mt-4">
                            <p className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Returnable packaging</p>
                            <p className="text-xs text-slate-500 mb-2">
                                The deposit-bearing container(s) this product carries when sold, and how many of each per unit (e.g. a crate of 12 links 1× Crate + 12× Bottle).
                            </p>
                            <div className="space-y-2">
                                {form.data.container_links.map((link, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <select
                                            value={link.container_product_id}
                                            onChange={(e) => updateContainerLink(i, { container_product_id: e.target.value })}
                                            className="flex-1 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        >
                                            {activeContainers.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}{c.deposit_amount ? ` — deposit ${Number(c.deposit_amount).toFixed(2)}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                        <input
                                            type="number" step="1" min="1"
                                            value={link.quantity_per_unit}
                                            onChange={(e) => updateContainerLink(i, { quantity_per_unit: e.target.value })}
                                            className="w-20 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => removeContainerLink(i)}
                                            className="text-xs font-semibold text-slate-400 hover:text-red-600 flex-shrink-0"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </div>
                            <button
                                type="button"
                                onClick={addContainerLink}
                                className="mt-2 text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                            >
                                + Add container
                            </button>
                        </div>
                    )}

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
                            <div key={c.id} className={`flex items-center gap-2 sm:gap-3 px-3 py-2 ${!c.is_active ? 'opacity-50' : ''}`}>
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
                                        className="flex-1 min-w-0 text-sm rounded-lg border border-emerald-300 px-2 py-1 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                ) : (
                                    <span className="flex-1 min-w-0 truncate text-sm text-slate-700">{c.name}</span>
                                )}
                                {!c.is_active && (
                                    <span className="hidden sm:inline text-[10px] font-semibold uppercase text-slate-400 flex-shrink-0">Archived</span>
                                )}
                                <button
                                    onClick={() => { setRenamingId(c.id); setRenameValue(c.name); }}
                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex-shrink-0"
                                >
                                    Rename
                                </button>
                                <button
                                    onClick={() => router.patch(`/office/categories/${c.id}/toggle-active`, {}, { preserveScroll: true })}
                                    className="text-xs font-semibold text-slate-400 hover:text-slate-600 flex-shrink-0"
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

            {/* Bulk import */}
            <Modal show={showImport} onClose={closeImport} maxWidth="lg">
                <form onSubmit={submitImport} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-1">Import Products &amp; Services</p>
                    <p className="text-sm text-slate-500 mb-4">
                        Download the template, fill in your items, then upload it here. New rows are added, and rows whose SKU or barcode
                        matches an existing item update it instead. Everything syncs to your connected devices automatically once it's in.
                    </p>

                    <div className="rounded-xl bg-slate-50 border border-slate-100 px-4 py-3 mb-4">
                        <p className="text-xs font-semibold text-slate-600 uppercase tracking-wide mb-2">Format</p>
                        <ul className="text-xs text-slate-600 space-y-1 list-disc list-inside">
                            <li><span className="font-mono">name</span>, <span className="font-mono">item_type</span> (product or service) and <span className="font-mono">price</span> are required.</li>
                            <li><span className="font-mono">cost_price</span>, <span className="font-mono">sku</span>, <span className="font-mono">barcode</span>, <span className="font-mono">unit</span> and <span className="font-mono">low_stock_threshold</span> are optional.</li>
                            <li><span className="font-mono">category</span> must match an existing category name exactly, or leave it blank.</li>
                            <li><span className="font-mono">track_stock</span> is yes/no; ignored for services.</li>
                            <li><span className="font-mono">stock_quantity</span> sets opening stock for new items only — it's ignored when updating an existing item, same as editing by hand.</li>
                        </ul>
                        <a
                            href="/office/products/import/template"
                            className="inline-block mt-3 text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                        >
                            Download template (.csv) →
                        </a>
                    </div>

                    <div>
                        <label className="text-xs font-semibold text-slate-500">CSV file</label>
                        <input
                            ref={importFileInput}
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) => importForm.setData('file', e.target.files?.[0] ?? null)}
                            className="mt-1 w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"
                        />
                        {importForm.errors.file && <p className="text-xs text-red-500 mt-1">{importForm.errors.file}</p>}
                    </div>

                    {locations.length > 1 && (
                        <div className="mt-4">
                            <label className="text-xs font-semibold text-slate-500">New items' opening stock goes to</label>
                            <select
                                value={importForm.data.location_id}
                                onChange={(e) => importForm.setData('location_id', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            >
                                {locations.map((l) => (
                                    <option key={l.id} value={l.id}>{l.name}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={closeImport} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={importForm.processing || !importForm.data.file} className="btn-primary py-2 disabled:opacity-50">
                            {importForm.processing ? 'Uploading…' : 'Upload & Sync'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Set exact stock balance at one location (opening balance / correction) */}
            <Modal show={balanceEdit !== null} onClose={() => setBalanceEdit(null)} maxWidth="sm">
                {balanceEdit && (
                    <form onSubmit={submitBalance} className="p-6">
                        <p className="text-base font-semibold text-slate-800">Set balance</p>
                        <p className="text-xs text-slate-400 mt-1">{balanceEdit.productName} · {balanceEdit.locationName}</p>

                        <div className="mt-4">
                            <label className="text-xs font-semibold text-slate-500">Exact quantity on hand</label>
                            <input
                                type="number"
                                step="any"
                                min="0"
                                autoFocus
                                value={balanceForm.data.quantity}
                                onChange={(e) => balanceForm.setData('quantity', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {balanceForm.errors.quantity && <p className="text-xs text-red-500 mt-1">{balanceForm.errors.quantity}</p>}
                            <p className="text-xs text-slate-400 mt-2">
                                This sets the balance to exactly this number — a stock movement is recorded for the
                                difference, and every till will pick up the change on its next sync.
                            </p>
                        </div>

                        <div className="mt-6 flex justify-end gap-2">
                            <button type="button" onClick={() => setBalanceEdit(null)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                                Cancel
                            </button>
                            <button type="submit" disabled={balanceForm.processing || balanceForm.data.quantity === ''} className="btn-primary py-2 disabled:opacity-50">
                                {balanceForm.processing ? 'Saving…' : 'Save'}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>
        </BackOfficeLayout>
    );
}
