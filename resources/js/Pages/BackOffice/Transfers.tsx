import React, { useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface CatalogItem {
    id: string;
    name: string;
    sku: string | null;
    barcode: string | null;
    cost_price: string;
}

interface LocationOption {
    id: string;
    name: string;
    type: 'shop' | 'warehouse';
}

interface TransferItemRow {
    id: string;
    product_id: string;
    product_name: string;
    qty_requested: string;
    qty_sent: string;
    qty_received: string;
}

type TransferStatus = 'pending' | 'approved' | 'in_transit' | 'received' | 'cancelled';

interface TransferRow {
    id: string;
    transfer_number: string;
    status: TransferStatus;
    notes: string | null;
    from_location: { id: string; name: string } | null;
    to_location: { id: string; name: string } | null;
    requested_by: { id: string; name: string } | null;
    approved_by: { id: string; name: string } | null;
    items: TransferItemRow[];
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface Props {
    transfers: Paginated<TransferRow>;
    locations: LocationOption[];
    catalog: CatalogItem[];
}

const STATUS_STYLE: Record<TransferStatus, { label: string; variant: 'amber' | 'blue' | 'violet' | 'green' | 'red' }> = {
    pending: { label: 'Pending', variant: 'amber' },
    approved: { label: 'Approved', variant: 'blue' },
    in_transit: { label: 'In Transit', variant: 'violet' },
    received: { label: 'Received', variant: 'green' },
    cancelled: { label: 'Cancelled', variant: 'red' },
};

interface FormItem {
    product_id: string;
    qty_requested: number;
}

interface ReceiveItem {
    product_id: string;
    qty: number;
    unit_cost: number;
}

export default function BackOfficeTransfers({ transfers, locations, catalog }: Props) {
    const [showNew, setShowNew] = useState(false);
    const [dispatching, setDispatching] = useState<TransferRow | null>(null);
    const [receiving, setReceiving] = useState<TransferRow | null>(null);
    const [qtyDraft, setQtyDraft] = useState<Record<string, number>>({});
    const [actionPending, setActionPending] = useState(false);
    const [showReceive, setShowReceive] = useState(false);
    const receiveImportFileInput = useRef<HTMLInputElement>(null);

    const { flash, errors } = usePage().props as unknown as {
        flash: { success: string | null; import_errors: string[] | null };
        errors: Record<string, string>;
    };

    const catalogById = Object.fromEntries(catalog.map((c) => [c.id, c]));

    const newForm = useForm<{ from_location_id: string; to_location_id: string; notes: string; items: FormItem[] }>({
        from_location_id: '',
        to_location_id: '',
        notes: '',
        items: [],
    });

    const openNew = () => {
        newForm.setData({ from_location_id: '', to_location_id: '', notes: '', items: [] });
        newForm.clearErrors();
        setShowNew(true);
    };

    const receiveForm = useForm<{ location_id: string; reason: string; items: ReceiveItem[] }>({
        location_id: locations[0]?.id ?? '',
        reason: '',
        items: [],
    });

    const receiveImportForm = useForm<{ file: File | null; location_id: string; reason: string }>({
        file: null,
        location_id: locations[0]?.id ?? '',
        reason: '',
    });

    const openReceiveStock = () => {
        receiveForm.setData({ location_id: locations[0]?.id ?? '', reason: '', items: [] });
        receiveForm.clearErrors();
        receiveImportForm.setData({ file: null, location_id: locations[0]?.id ?? '', reason: '' });
        receiveImportForm.clearErrors();
        if (receiveImportFileInput.current) receiveImportFileInput.current.value = '';
        setShowReceive(true);
    };

    const addReceiveItem = (productId: string) => {
        if (!productId || receiveForm.data.items.some((i) => i.product_id === productId)) return;
        const cost = Number(catalogById[productId]?.cost_price ?? 0);
        receiveForm.setData('items', [...receiveForm.data.items, { product_id: productId, qty: 1, unit_cost: cost }]);
    };

    const updateReceiveItem = (productId: string, patch: Partial<ReceiveItem>) => {
        receiveForm.setData('items', receiveForm.data.items.map((i) => (i.product_id === productId ? { ...i, ...patch } : i)));
    };

    const removeReceiveItem = (productId: string) => {
        receiveForm.setData('items', receiveForm.data.items.filter((i) => i.product_id !== productId));
    };

    const submitReceiveStock = (e: React.FormEvent) => {
        e.preventDefault();
        receiveForm.post('/office/products/receive-stock', { preserveScroll: true, onSuccess: () => setShowReceive(false) });
    };

    const setReceiveLocation = (locationId: string) => {
        receiveForm.setData('location_id', locationId);
        receiveImportForm.setData('location_id', locationId);
    };

    const submitReceiveImport = (e: React.FormEvent) => {
        e.preventDefault();
        if (!receiveImportForm.data.file) return;
        receiveImportForm.post('/office/products/receive-stock/import', {
            preserveScroll: true,
            onSuccess: () => {
                setShowReceive(false);
                receiveImportForm.setData('file', null);
                if (receiveImportFileInput.current) receiveImportFileInput.current.value = '';
            },
        });
    };

    const addItem = (productId: string) => {
        if (!productId || newForm.data.items.some((i) => i.product_id === productId)) return;
        newForm.setData('items', [...newForm.data.items, { product_id: productId, qty_requested: 1 }]);
    };

    const setReqQty = (productId: string, qty: number) => {
        newForm.setData('items', newForm.data.items.map((i) => (i.product_id === productId ? { ...i, qty_requested: qty } : i)));
    };

    const removeItem = (productId: string) => {
        newForm.setData('items', newForm.data.items.filter((i) => i.product_id !== productId));
    };

    const submitNew = (e: React.FormEvent) => {
        e.preventDefault();
        newForm.post('/office/transfers', { preserveScroll: true, onSuccess: () => setShowNew(false) });
    };

    const openDispatch = (transfer: TransferRow) => {
        setQtyDraft(Object.fromEntries(transfer.items.map((i) => [i.id, Number(i.qty_requested)])));
        setDispatching(transfer);
    };

    const submitDispatch = (e: React.FormEvent) => {
        e.preventDefault();
        if (!dispatching) return;
        setActionPending(true);
        router.post(
            `/office/transfers/${dispatching.id}/dispatch`,
            { items: dispatching.items.map((i) => ({ item_id: i.id, qty_sent: qtyDraft[i.id] ?? 0 })) },
            { preserveScroll: true, onFinish: () => setActionPending(false), onSuccess: () => setDispatching(null) }
        );
    };

    const openReceive = (transfer: TransferRow) => {
        setQtyDraft(Object.fromEntries(transfer.items.map((i) => [i.id, Number(i.qty_sent)])));
        setReceiving(transfer);
    };

    const submitReceive = (e: React.FormEvent) => {
        e.preventDefault();
        if (!receiving) return;
        setActionPending(true);
        router.post(
            `/office/transfers/${receiving.id}/receive`,
            { items: receiving.items.map((i) => ({ item_id: i.id, qty_received: qtyDraft[i.id] ?? 0 })) },
            { preserveScroll: true, onFinish: () => setActionPending(false), onSuccess: () => setReceiving(null) }
        );
    };

    return (
        <BackOfficeLayout>
            <Head title="Transfers" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Stock Transfers</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Receive stock into a location, then move it between shops and warehouses. Requested, dispatched, then received —
                        every step updates every till.
                    </p>
                </div>
                <div className="flex gap-2 flex-shrink-0">
                    <button
                        onClick={openReceiveStock}
                        disabled={locations.length === 0}
                        className="text-sm px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:border-emerald-300 disabled:opacity-50"
                    >
                        + Receive Stock
                    </button>
                    <button
                        onClick={openNew}
                        disabled={locations.length < 2}
                        title={locations.length < 2 ? 'Add a second location first' : undefined}
                        className="btn-primary py-2 disabled:opacity-50"
                    >
                        + New Transfer
                    </button>
                </div>
            </div>

            {locations.length < 2 && (
                <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    You need at least two locations to move stock between them.{' '}
                    <Link href="/office/locations" className="font-semibold underline">Add a location</Link>.
                </div>
            )}

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

            {errors?.items && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{errors.items}</div>
            )}
            {errors?.transfer && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{errors.transfer}</div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                {/* Mobile: card list */}
                <div className="md:hidden divide-y divide-slate-50">
                    {transfers.data.map((t) => (
                        <div key={t.id} className="p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-slate-900 truncate">{t.transfer_number}</p>
                                    <p className="text-xs text-slate-400 mt-0.5">
                                        {t.from_location?.name ?? '—'} → {t.to_location?.name ?? '—'}
                                    </p>
                                </div>
                                <StatusBadge label={STATUS_STYLE[t.status].label} variant={STATUS_STYLE[t.status].variant} />
                            </div>
                            <p className="text-xs text-slate-500 mt-2">{t.items.length} item(s) · {new Date(t.created_at).toLocaleDateString()}</p>
                            <TransferActions transfer={t} onApprove={approve} onDispatch={openDispatch} onReceive={openReceive} onCancel={cancel} />
                        </div>
                    ))}
                    {transfers.data.length === 0 && (
                        <p className="px-4 py-10 text-center text-sm text-slate-400">No transfers yet.</p>
                    )}
                </div>

                {/* Desktop: table */}
                <div className="hidden md:block overflow-x-auto">
                    <table className="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Transfer #</th>
                                <th className="table-th">Route</th>
                                <th className="table-th">Items</th>
                                <th className="table-th">Requested by</th>
                                <th className="table-th">Date</th>
                                <th className="table-th">Status</th>
                                <th className="table-th text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {transfers.data.map((t) => (
                                <tr key={t.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">{t.transfer_number}</td>
                                    <td className="table-td text-slate-600">{t.from_location?.name ?? '—'} → {t.to_location?.name ?? '—'}</td>
                                    <td className="table-td text-slate-600">{t.items.length}</td>
                                    <td className="table-td text-slate-600">{t.requested_by?.name ?? '—'}</td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">{new Date(t.created_at).toLocaleDateString()}</td>
                                    <td className="table-td"><StatusBadge label={STATUS_STYLE[t.status].label} variant={STATUS_STYLE[t.status].variant} /></td>
                                    <td className="table-td text-right">
                                        <TransferActions transfer={t} onApprove={approve} onDispatch={openDispatch} onReceive={openReceive} onCancel={cancel} inline />
                                    </td>
                                </tr>
                            ))}
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="table-td text-center text-slate-400 py-10">No transfers yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {transfers.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {transfers.links.map((link, i) =>
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

            {/* New transfer modal */}
            <Modal show={showNew} onClose={() => setShowNew(false)} maxWidth="lg">
                <form onSubmit={submitNew} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">New Transfer</p>

                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">From</label>
                                <select
                                    value={newForm.data.from_location_id}
                                    onChange={(e) => newForm.setData('from_location_id', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">Choose location…</option>
                                    {locations.map((l) => (
                                        <option key={l.id} value={l.id} disabled={l.id === newForm.data.to_location_id}>{l.name}</option>
                                    ))}
                                </select>
                                {newForm.errors.from_location_id && <p className="text-xs text-red-500 mt-1">{newForm.errors.from_location_id}</p>}
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">To</label>
                                <select
                                    value={newForm.data.to_location_id}
                                    onChange={(e) => newForm.setData('to_location_id', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">Choose location…</option>
                                    {locations.map((l) => (
                                        <option key={l.id} value={l.id} disabled={l.id === newForm.data.from_location_id}>{l.name}</option>
                                    ))}
                                </select>
                                {newForm.errors.to_location_id && <p className="text-xs text-red-500 mt-1">{newForm.errors.to_location_id}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Add item</label>
                            <ProductPicker
                                catalog={catalog}
                                excludeIds={newForm.data.items.map((i) => i.product_id)}
                                onSelect={addItem}
                            />
                            {newForm.errors.items && <p className="text-xs text-red-500 mt-1">{newForm.errors.items}</p>}
                        </div>

                        {newForm.data.items.length > 0 && (
                            <div className="rounded-xl border border-slate-100 divide-y divide-slate-50">
                                {newForm.data.items.map((item) => (
                                    <div key={item.product_id} className="flex items-center gap-3 px-3 py-2">
                                        <span className="flex-1 min-w-0 text-sm text-slate-700 truncate">{catalogById[item.product_id]?.name ?? item.product_id}</span>
                                        <input
                                            type="number" min="0.0001" step="any"
                                            value={item.qty_requested}
                                            onChange={(e) => setReqQty(item.product_id, Number(e.target.value))}
                                            className="w-20 text-sm rounded-lg border border-slate-200 px-2 py-1 text-right focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                        <button type="button" onClick={() => removeItem(item.product_id)} className="text-xs font-semibold text-red-400 hover:text-red-600">
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Notes (optional)</label>
                            <input
                                type="text"
                                value={newForm.data.notes}
                                onChange={(e) => newForm.setData('notes', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowNew(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={newForm.processing || newForm.data.items.length === 0 || !newForm.data.from_location_id || !newForm.data.to_location_id}
                            className="btn-primary py-2 disabled:opacity-50"
                        >
                            {newForm.processing ? 'Requesting…' : 'Request Transfer'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Receive stock modal */}
            <Modal show={showReceive} onClose={() => setShowReceive(false)} maxWidth="lg">
                <div className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-1">Receive Stock</p>
                    <p className="text-xs text-slate-500 mb-4">
                        Record a delivery arriving directly into a location — no purchase order required. Cost price is recalculated as a
                        weighted average across existing and received stock, same as receiving on a till.
                    </p>

                    <div className="mb-4">
                        <label className="text-xs font-semibold text-slate-500">Receiving location</label>
                        <select
                            value={receiveForm.data.location_id}
                            onChange={(e) => setReceiveLocation(e.target.value)}
                            className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        >
                            <option value="">Choose location…</option>
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>{l.name}</option>
                            ))}
                        </select>
                        {(receiveForm.errors.location_id || receiveImportForm.errors.location_id) && (
                            <p className="text-xs text-red-500 mt-1">{receiveForm.errors.location_id ?? receiveImportForm.errors.location_id}</p>
                        )}
                    </div>

                    <form onSubmit={submitReceiveStock} className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Add item</label>
                            <ProductPicker
                                catalog={catalog}
                                excludeIds={receiveForm.data.items.map((i) => i.product_id)}
                                onSelect={addReceiveItem}
                            />
                            {receiveForm.errors.items && <p className="text-xs text-red-500 mt-1">{receiveForm.errors.items}</p>}
                        </div>

                        {receiveForm.data.items.length > 0 && (
                            <div className="rounded-xl border border-slate-100 divide-y divide-slate-50">
                                <div className="flex items-center gap-3 px-3 py-1.5 text-[11px] font-semibold text-slate-400 uppercase tracking-wide">
                                    <span className="flex-1">Item</span>
                                    <span className="w-20 text-right">Qty</span>
                                    <span className="w-24 text-right">Unit cost</span>
                                    <span className="w-12"></span>
                                </div>
                                {receiveForm.data.items.map((item) => (
                                    <div key={item.product_id} className="flex items-center gap-3 px-3 py-2">
                                        <span className="flex-1 min-w-0 text-sm text-slate-700 truncate">{catalogById[item.product_id]?.name ?? item.product_id}</span>
                                        <input
                                            type="number" min="0.0001" step="any"
                                            value={item.qty}
                                            onChange={(e) => updateReceiveItem(item.product_id, { qty: Number(e.target.value) })}
                                            className="w-20 text-sm rounded-lg border border-slate-200 px-2 py-1 text-right focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                        <input
                                            type="number" min="0" step="any"
                                            value={item.unit_cost}
                                            onChange={(e) => updateReceiveItem(item.product_id, { unit_cost: Number(e.target.value) })}
                                            className="w-24 text-sm rounded-lg border border-slate-200 px-2 py-1 text-right focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                        />
                                        <button type="button" onClick={() => removeReceiveItem(item.product_id)} className="w-12 text-xs font-semibold text-red-400 hover:text-red-600 text-right">
                                            Remove
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Note (optional)</label>
                            <input
                                type="text"
                                placeholder="e.g. delivery note number, supplier"
                                value={receiveForm.data.reason}
                                onChange={(e) => receiveForm.setData('reason', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>

                        <div className="flex justify-end">
                            <button
                                type="submit"
                                disabled={receiveForm.processing || receiveForm.data.items.length === 0 || !receiveForm.data.location_id}
                                className="btn-primary py-2 disabled:opacity-50"
                            >
                                {receiveForm.processing ? 'Receiving…' : 'Receive Stock'}
                            </button>
                        </div>
                    </form>

                    <div className="my-5 flex items-center gap-3">
                        <div className="h-px flex-1 bg-slate-100" />
                        <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">or import a CSV</span>
                        <div className="h-px flex-1 bg-slate-100" />
                    </div>

                    <form onSubmit={submitReceiveImport} className="space-y-3">
                        <p className="text-xs text-slate-500">
                            For a big delivery, download a template prefilled with every stocked product's SKU, barcode and current cost —
                            fill in the quantities that arrived (leave the rest blank), then upload it here.
                        </p>
                        <a
                            href="/office/products/receive-stock/template"
                            className="inline-block text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                        >
                            Download receiving template (.csv) →
                        </a>
                        <div>
                            <input
                                ref={receiveImportFileInput}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(e) => receiveImportForm.setData('file', e.target.files?.[0] ?? null)}
                                className="mt-1 w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"
                            />
                            {receiveImportForm.errors.file && <p className="text-xs text-red-500 mt-1">{receiveImportForm.errors.file}</p>}
                        </div>
                        <input
                            type="text"
                            placeholder="Note (optional) — e.g. delivery note number, supplier"
                            value={receiveImportForm.data.reason}
                            onChange={(e) => receiveImportForm.setData('reason', e.target.value)}
                            className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                        <div className="flex justify-end">
                            <button
                                type="submit"
                                disabled={receiveImportForm.processing || !receiveImportForm.data.file || !receiveImportForm.data.location_id}
                                className="text-sm px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:border-emerald-300 disabled:opacity-50"
                            >
                                {receiveImportForm.processing ? 'Uploading…' : 'Upload & Receive'}
                            </button>
                        </div>
                    </form>

                    <div className="mt-4 flex justify-end">
                        <button type="button" onClick={() => setShowReceive(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Close
                        </button>
                    </div>
                </div>
            </Modal>

            {/* Dispatch modal */}
            <Modal show={dispatching !== null} onClose={() => setDispatching(null)} maxWidth="md">
                {dispatching && (
                    <form onSubmit={submitDispatch} className="p-6">
                        <p className="text-base font-semibold text-slate-800 mb-1">Dispatch {dispatching.transfer_number}</p>
                        <p className="text-xs text-slate-500 mb-4">
                            Confirm how much of each item is actually leaving {dispatching.from_location?.name}. Stock is reserved, not yet deducted.
                        </p>
                        <div className="rounded-xl border border-slate-100 divide-y divide-slate-50">
                            {dispatching.items.map((item) => (
                                <div key={item.id} className="flex items-center gap-3 px-3 py-2">
                                    <span className="flex-1 min-w-0 text-sm text-slate-700 truncate">
                                        {item.product_name} <span className="text-slate-400">· requested {Number(item.qty_requested)}</span>
                                    </span>
                                    <input
                                        type="number" min="0" step="any"
                                        value={qtyDraft[item.id] ?? 0}
                                        onChange={(e) => setQtyDraft({ ...qtyDraft, [item.id]: Number(e.target.value) })}
                                        className="w-20 text-sm rounded-lg border border-slate-200 px-2 py-1 text-right focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                            ))}
                        </div>
                        <div className="mt-6 flex justify-end gap-2">
                            <button type="button" onClick={() => setDispatching(null)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                                Cancel
                            </button>
                            <button type="submit" disabled={actionPending} className="btn-primary py-2 disabled:opacity-50">
                                {actionPending ? 'Dispatching…' : 'Confirm Dispatch'}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>

            {/* Receive modal */}
            <Modal show={receiving !== null} onClose={() => setReceiving(null)} maxWidth="md">
                {receiving && (
                    <form onSubmit={submitReceive} className="p-6">
                        <p className="text-base font-semibold text-slate-800 mb-1">Receive {receiving.transfer_number}</p>
                        <p className="text-xs text-slate-500 mb-4">
                            Confirm how much of each item actually arrived at {receiving.to_location?.name}. Anything short is logged as a loss.
                        </p>
                        <div className="rounded-xl border border-slate-100 divide-y divide-slate-50">
                            {receiving.items.map((item) => (
                                <div key={item.id} className="flex items-center gap-3 px-3 py-2">
                                    <span className="flex-1 min-w-0 text-sm text-slate-700 truncate">
                                        {item.product_name} <span className="text-slate-400">· sent {Number(item.qty_sent)}</span>
                                    </span>
                                    <input
                                        type="number" min="0" step="any"
                                        value={qtyDraft[item.id] ?? 0}
                                        onChange={(e) => setQtyDraft({ ...qtyDraft, [item.id]: Number(e.target.value) })}
                                        className="w-20 text-sm rounded-lg border border-slate-200 px-2 py-1 text-right focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                    />
                                </div>
                            ))}
                        </div>
                        <div className="mt-6 flex justify-end gap-2">
                            <button type="button" onClick={() => setReceiving(null)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                                Cancel
                            </button>
                            <button type="submit" disabled={actionPending} className="btn-primary py-2 disabled:opacity-50">
                                {actionPending ? 'Receiving…' : 'Confirm Receipt'}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>
        </BackOfficeLayout>
    );
}

function approve(transfer: TransferRow) {
    router.post(`/office/transfers/${transfer.id}/approve`, {}, { preserveScroll: true });
}

function cancel(transfer: TransferRow) {
    router.post(`/office/transfers/${transfer.id}/cancel`, {}, { preserveScroll: true });
}

function TransferActions({
    transfer,
    onApprove,
    onDispatch,
    onReceive,
    onCancel,
    inline = false,
}: {
    transfer: TransferRow;
    onApprove: (t: TransferRow) => void;
    onDispatch: (t: TransferRow) => void;
    onReceive: (t: TransferRow) => void;
    onCancel: (t: TransferRow) => void;
    inline?: boolean;
}) {
    const buttons: React.ReactNode[] = [];

    if (transfer.status === 'pending') {
        buttons.push(
            <button key="approve" onClick={() => onApprove(transfer)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">Approve</button>,
            <button key="dispatch" onClick={() => onDispatch(transfer)} className="text-xs font-semibold text-sky-600 hover:text-sky-800">Dispatch</button>,
            <button key="cancel" onClick={() => onCancel(transfer)} className="text-xs font-semibold text-red-400 hover:text-red-600">Cancel</button>
        );
    } else if (transfer.status === 'approved') {
        buttons.push(
            <button key="dispatch" onClick={() => onDispatch(transfer)} className="text-xs font-semibold text-sky-600 hover:text-sky-800">Dispatch</button>,
            <button key="cancel" onClick={() => onCancel(transfer)} className="text-xs font-semibold text-red-400 hover:text-red-600">Cancel</button>
        );
    } else if (transfer.status === 'in_transit') {
        buttons.push(
            <button key="receive" onClick={() => onReceive(transfer)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">Receive</button>
        );
    }

    if (buttons.length === 0) {
        return inline ? <span className="text-xs text-slate-300">—</span> : null;
    }

    return <div className={inline ? 'flex items-center justify-end gap-3' : 'flex items-center gap-3 mt-3'}>{buttons}</div>;
}

/**
 * Type-to-search product picker: filters the full catalog by name, SKU or
 * barcode as you type, since a plain <select> stops being usable once a
 * catalog grows past a couple dozen items. onMouseDown (not onClick) on
 * each result fires before the input's onBlur closes the dropdown, so a
 * click still registers.
 */
function ProductPicker({
    catalog,
    excludeIds,
    onSelect,
    placeholder = 'Search by name, SKU, or barcode…',
}: {
    catalog: CatalogItem[];
    excludeIds: string[];
    onSelect: (productId: string) => void;
    placeholder?: string;
}) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);

    const q = query.trim().toLowerCase();
    const results = q === ''
        ? []
        : catalog
              .filter((c) => !excludeIds.includes(c.id))
              .filter(
                  (c) =>
                      c.name.toLowerCase().includes(q) ||
                      (c.sku ?? '').toLowerCase().includes(q) ||
                      (c.barcode ?? '').toLowerCase().includes(q)
              )
              .slice(0, 8);

    const pick = (id: string) => {
        onSelect(id);
        setQuery('');
        setOpen(false);
    };

    return (
        <div className="relative">
            <input
                type="text"
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                placeholder={placeholder}
                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
            />
            {open && q !== '' && (
                <div className="absolute z-10 mt-1 w-full max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                    {results.length === 0 ? (
                        <p className="px-3 py-2 text-sm text-slate-400">No matches.</p>
                    ) : (
                        results.map((c) => (
                            <button
                                key={c.id}
                                type="button"
                                onMouseDown={() => pick(c.id)}
                                className="w-full text-left px-3 py-2 text-sm hover:bg-emerald-50 flex items-center justify-between gap-2"
                            >
                                <span className="truncate">{c.name}</span>
                                <span className="text-xs text-slate-400 flex-shrink-0">{c.sku ?? c.barcode ?? ''}</span>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
