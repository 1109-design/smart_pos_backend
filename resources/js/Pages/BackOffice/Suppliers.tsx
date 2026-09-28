import React, { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface SupplierRow {
    id: string;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    website: string | null;
    notes: string | null;
    tax_number: string | null;
    is_active: boolean;
}

interface Props {
    suppliers: SupplierRow[];
    filters: { search: string };
}

const EMPTY_FORM = {
    name: '',
    contact_name: '',
    phone: '',
    email: '',
    address: '',
    website: '',
    tax_number: '',
    notes: '',
};

export default function BackOfficeSuppliers({ suppliers, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<SupplierRow | null>(null);
    const [showForm, setShowForm] = useState(false);

    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const form = useForm({ ...EMPTY_FORM });

    const openCreate = () => {
        form.setData({ ...EMPTY_FORM });
        form.clearErrors();
        setEditing(null);
        setShowForm(true);
    };

    const openEdit = (supplier: SupplierRow) => {
        form.setData({
            name: supplier.name,
            contact_name: supplier.contact_name ?? '',
            phone: supplier.phone ?? '',
            email: supplier.email ?? '',
            address: supplier.address ?? '',
            website: supplier.website ?? '',
            tax_number: supplier.tax_number ?? '',
            notes: supplier.notes ?? '',
        });
        form.clearErrors();
        setEditing(supplier);
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        if (editing) {
            form.put(`/office/suppliers/${editing.id}`, options);
        } else {
            form.post('/office/suppliers', options);
        }
    };

    const runSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/office/suppliers', { search }, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Suppliers" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Suppliers</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Who you buy stock from. Add one here and it's ready to pick from on the till's next Purchase Order.
                    </p>
                </div>
                <div className="flex items-center gap-3 flex-shrink-0">
                    <ImportOpeningBalancesButton />
                    <button onClick={openCreate} className="btn-primary py-2">+ New Supplier</button>
                </div>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <form onSubmit={runSearch} className="mb-4">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search suppliers…"
                    className="w-full sm:w-80 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </form>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {suppliers.map((supplier) => (
                    <div key={supplier.id} className={`bg-white rounded-2xl border border-slate-100 shadow-sm p-5 ${!supplier.is_active ? 'opacity-50' : ''}`}>
                        <div className="flex items-start justify-between gap-2 mb-3">
                            <div className="min-w-0">
                                <Link href={`/office/suppliers/${supplier.id}`} className="text-sm font-semibold text-slate-900 truncate hover:text-emerald-700 hover:underline block">
                                    {supplier.name}
                                </Link>
                                {supplier.contact_name && <p className="text-xs text-slate-500 mt-0.5 truncate">{supplier.contact_name}</p>}
                            </div>
                            {!supplier.is_active && <StatusBadge label="Inactive" variant="gray" />}
                        </div>

                        <div className="text-xs text-slate-500 space-y-0.5 mb-4">
                            {supplier.phone && <p>{supplier.phone}</p>}
                            {supplier.email && <p>{supplier.email}</p>}
                            {supplier.address && <p className="truncate">{supplier.address}</p>}
                        </div>

                        <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                            <button onClick={() => openEdit(supplier)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">
                                Edit
                            </button>
                            <button
                                onClick={() => router.patch(`/office/suppliers/${supplier.id}/toggle-active`, {}, { preserveScroll: true })}
                                className="text-xs font-semibold text-slate-400 hover:text-slate-600"
                            >
                                {supplier.is_active ? 'Deactivate' : 'Restore'}
                            </button>
                        </div>
                    </div>
                ))}

                {suppliers.length === 0 && (
                    <p className="col-span-full text-center text-sm text-slate-400 py-10">No suppliers yet.</p>
                )}
            </div>

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="md">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">{editing ? 'Edit Supplier' : 'New Supplier'}</p>

                    <div className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Name</label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Delta Beverages"
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Contact person (optional)</label>
                                <input
                                    type="text"
                                    value={form.data.contact_name}
                                    onChange={(e) => form.setData('contact_name', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Phone (optional)</label>
                                <input
                                    type="text"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Email (optional)</label>
                                <input
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Tax number (optional)</label>
                                <input
                                    type="text"
                                    value={form.data.tax_number}
                                    onChange={(e) => form.setData('tax_number', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Address (optional)</label>
                            <input
                                type="text"
                                value={form.data.address}
                                onChange={(e) => form.setData('address', e.target.value)}
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                        </div>

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
                            {form.processing ? 'Saving…' : editing ? 'Save Changes' : 'Create Supplier'}
                        </button>
                    </div>
                </form>
            </Modal>
        </BackOfficeLayout>
    );
}

/**
 * Onboarding path for many suppliers at once: download a template pre-filled
 * with every active supplier, fill in "opening_balance", re-upload. One CSV
 * upload = one manager sign-off for the whole batch — see
 * SupplierOpeningBalancesController::import()'s doc comment.
 */
function ImportOpeningBalancesButton() {
    const [uploading, setUploading] = useState(false);

    const upload = (selected: File) => {
        setUploading(true);
        router.post(
            '/office/suppliers/opening-balances/import',
            { file: selected },
            { forceFormData: true, preserveScroll: true, onFinish: () => setUploading(false) }
        );
    };

    return (
        <div className="flex items-center gap-2 text-xs">
            <a
                href="/office/suppliers/opening-balances/template"
                className="font-semibold text-emerald-700 hover:text-emerald-900 whitespace-nowrap"
            >
                Download opening-balance template
            </a>
            <label className="font-semibold text-slate-500 hover:text-slate-700 cursor-pointer whitespace-nowrap">
                {uploading ? 'Uploading…' : 'Import CSV'}
                <input
                    type="file"
                    accept=".csv,text/csv"
                    className="hidden"
                    disabled={uploading}
                    onChange={(e) => {
                        const selected = e.target.files?.[0];
                        if (selected) upload(selected);
                        e.target.value = '';
                    }}
                />
            </label>
        </div>
    );
}
