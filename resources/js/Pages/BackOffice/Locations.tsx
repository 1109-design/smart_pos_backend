import React, { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface LocationRow {
    id: string;
    parent_id: string | null;
    name: string;
    type: 'shop' | 'warehouse';
    address: string | null;
    phone: string | null;
    email: string | null;
    can_sell: boolean;
    can_receive: boolean;
    is_active: boolean;
}

interface Props {
    locations: LocationRow[];
}

const EMPTY_FORM = {
    name: '',
    type: 'shop' as 'shop' | 'warehouse',
    parent_id: '',
    address: '',
    phone: '',
    email: '',
    can_sell: true,
    can_receive: true,
};

export default function BackOfficeLocations({ locations }: Props) {
    const [editing, setEditing] = useState<LocationRow | null>(null);
    const [showForm, setShowForm] = useState(false);

    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const form = useForm({ ...EMPTY_FORM });

    const warehouses = locations.filter((l) => l.type === 'warehouse');

    const openCreate = () => {
        form.setData({ ...EMPTY_FORM });
        form.clearErrors();
        setEditing(null);
        setShowForm(true);
    };

    const openEdit = (location: LocationRow) => {
        form.setData({
            name: location.name,
            type: location.type,
            parent_id: location.parent_id ?? '',
            address: location.address ?? '',
            phone: location.phone ?? '',
            email: location.email ?? '',
            can_sell: location.can_sell,
            can_receive: location.can_receive,
        });
        form.clearErrors();
        setEditing(location);
        setShowForm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setShowForm(false) };
        if (editing) {
            form.put(`/office/locations/${editing.id}`, options);
        } else {
            form.post('/office/locations', options);
        }
    };

    return (
        <BackOfficeLayout>
            <Head title="Locations" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Locations</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Shops and warehouses stock is tracked against. Add one here and it appears on every till next sync.
                    </p>
                </div>
                <button onClick={openCreate} className="btn-primary py-2 flex-shrink-0">+ New Location</button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                {locations.map((location) => (
                    <div key={location.id} className={`bg-white rounded-2xl border border-slate-100 shadow-sm p-5 ${!location.is_active ? 'opacity-50' : ''}`}>
                        <div className="flex items-start justify-between gap-2 mb-3">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-slate-900 truncate">{location.name}</p>
                                {location.address && <p className="text-xs text-slate-500 mt-0.5 truncate">{location.address}</p>}
                            </div>
                            <StatusBadge
                                label={location.type === 'warehouse' ? 'Warehouse' : 'Shop'}
                                variant={location.type === 'warehouse' ? 'violet' : 'blue'}
                            />
                        </div>

                        <div className="flex flex-wrap gap-1.5 mb-4">
                            {!location.is_active && <StatusBadge label="Inactive" variant="gray" />}
                            {location.can_sell && <StatusBadge label="Sells" variant="green" dot={false} />}
                            {location.can_receive && <StatusBadge label="Receives stock" variant="green" dot={false} />}
                            {location.parent_id && (
                                <StatusBadge
                                    label={`Fed by ${warehouses.find((w) => w.id === location.parent_id)?.name ?? '—'}`}
                                    variant="gray"
                                    dot={false}
                                />
                            )}
                        </div>

                        <div className="text-xs text-slate-500 space-y-0.5 mb-4">
                            {location.phone && <p>{location.phone}</p>}
                            {location.email && <p>{location.email}</p>}
                        </div>

                        <div className="flex items-center justify-between border-t border-slate-100 pt-3">
                            <button onClick={() => openEdit(location)} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">
                                Edit
                            </button>
                            <button
                                onClick={() => router.patch(`/office/locations/${location.id}/toggle-active`, {}, { preserveScroll: true })}
                                className="text-xs font-semibold text-slate-400 hover:text-slate-600"
                            >
                                {location.is_active ? 'Deactivate' : 'Restore'}
                            </button>
                        </div>
                    </div>
                ))}
            </div>

            <Modal show={showForm} onClose={() => setShowForm(false)} maxWidth="md">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-4">{editing ? 'Edit Location' : 'New Location'}</p>

                    <div className="space-y-4">
                        <div>
                            <label className="text-xs font-semibold text-slate-500">Name</label>
                            <input
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Main Street Shop"
                                className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            {form.errors.name && <p className="text-xs text-red-500 mt-1">{form.errors.name}</p>}
                        </div>

                        <div>
                            <label className="text-xs font-semibold text-slate-500">Type</label>
                            <div className="mt-1 flex gap-2">
                                {(['shop', 'warehouse'] as const).map((type) => (
                                    <button
                                        key={type}
                                        type="button"
                                        onClick={() => form.setData('type', type)}
                                        className={`flex-1 text-sm px-3 py-2 rounded-xl border capitalize ${
                                            form.data.type === type
                                                ? 'bg-emerald-600 border-emerald-600 text-white'
                                                : 'bg-white border-slate-200 text-slate-600'
                                        }`}
                                    >
                                        {type}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {form.data.type === 'shop' && warehouses.length > 0 && (
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Supplying warehouse (optional)</label>
                                <select
                                    value={form.data.parent_id}
                                    onChange={(e) => form.setData('parent_id', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                >
                                    <option value="">None</option>
                                    {warehouses.filter((w) => w.id !== editing?.id).map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Phone (optional)</label>
                                <input
                                    type="text"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                                />
                            </div>
                            <div>
                                <label className="text-xs font-semibold text-slate-500">Email (optional)</label>
                                <input
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
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

                        <div className="flex gap-4">
                            <label className="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" checked={form.data.can_sell} onChange={(e) => form.setData('can_sell', e.target.checked)} />
                                Sells to customers
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" checked={form.data.can_receive} onChange={(e) => form.setData('can_receive', e.target.checked)} />
                                Can receive transfers
                            </label>
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowForm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button type="submit" disabled={form.processing} className="btn-primary py-2 disabled:opacity-50">
                            {form.processing ? 'Saving…' : editing ? 'Save Changes' : 'Create Location'}
                        </button>
                    </div>
                </form>
            </Modal>
        </BackOfficeLayout>
    );
}
