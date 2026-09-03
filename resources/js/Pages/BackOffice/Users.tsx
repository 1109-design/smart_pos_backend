import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';
import Modal from '@/Components/Modal';

interface User {
    id: string;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
    location_ids: string[];
    location_names: string[];
}

interface LocationOption {
    id: string;
    name: string;
}

interface Props {
    users: User[];
    roles: string[];
    locations: LocationOption[];
    viewer_role: string;
    filters: { search: string };
}

function roleVariant(role: string | null): 'blue' | 'amber' | 'gray' {
    if (role === 'business_owner') return 'blue';
    if (role === 'manager')        return 'amber';
    return 'gray';
}

function roleLabel(role: string): string {
    return role.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

type ModalMode = 'create' | 'edit' | 'password' | 'locations' | null;

export default function BackOfficeUsers({ users, roles, locations, viewer_role, filters }: Props) {
    const [modalMode, setModalMode]     = useState<ModalMode>(null);
    const [selected, setSelected]       = useState<User | null>(null);
    const [search, setSearch]           = useState(filters.search);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleSearch = useCallback((value: string) => {
        setSearch(value);
        if (debounceRef.current) { clearTimeout(debounceRef.current); }
        debounceRef.current = setTimeout(() => {
            router.get('/office/users', { search: value }, { preserveState: true, replace: true });
        }, 350);
    }, []);

    useEffect(() => () => { if (debounceRef.current) { clearTimeout(debounceRef.current); } }, []);

    const editForm = useForm({ name: '', email: '', role: 'cashier', is_active: true as boolean, pin: '' });
    const pwForm   = useForm({ password: '', password_confirmation: '' });
    const locForm  = useForm<{ location_ids: string[] }>({ location_ids: [] });

    const openCreate = () => {
        setSelected(null);
        editForm.reset();
        editForm.clearErrors();
        editForm.setData({ name: '', email: '', role: 'cashier', is_active: true, pin: '' });
        setModalMode('create');
    };

    const openEdit = (u: User) => {
        setSelected(u);
        editForm.clearErrors();
        editForm.setData({ name: u.name, email: u.email, role: u.role ?? 'cashier', is_active: u.is_active, pin: '' });
        setModalMode('edit');
    };

    const openPassword = (u: User) => {
        setSelected(u);
        pwForm.reset();
        setModalMode('password');
    };

    const closeModal = () => { setModalMode(null); setSelected(null); };

    const openLocations = (u: User) => {
        setSelected(u);
        locForm.clearErrors();
        locForm.setData('location_ids', u.location_ids);
        setModalMode('locations');
    };

    const submitLocations = (e: React.FormEvent) => {
        e.preventDefault();
        locForm.put(`/office/users/${selected!.id}/locations`, { onSuccess: closeModal });
    };

    const toggleLocationId = (id: string) => {
        const next = locForm.data.location_ids.includes(id)
            ? locForm.data.location_ids.filter((x) => x !== id)
            : [...locForm.data.location_ids, id];
        locForm.setData('location_ids', next);
    };

    const submitUserForm = (e: React.FormEvent) => {
        e.preventDefault();
        if (modalMode === 'create') {
            editForm.post('/office/users', { onSuccess: closeModal });
        } else {
            editForm.put(`/office/users/${selected!.id}`, { onSuccess: closeModal });
        }
    };

    const submitPassword = (e: React.FormEvent) => {
        e.preventDefault();
        pwForm.put(`/office/users/${selected!.id}/password`, { onSuccess: closeModal });
    };

    const toggleActive = (u: User) => {
        const action = u.is_active ? 'deactivate' : 'activate';
        if (confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} ${u.name}?`)) {
            router.patch(`/office/users/${u.id}/toggle-active`);
        }
    };

    const isOwner = viewer_role === 'business_owner';

    const CloseBtn = () => (
        <button type="button" onClick={closeModal} className="text-slate-400 hover:text-slate-600">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
            </svg>
        </button>
    );

    return (
        <BackOfficeLayout>
            <Head title="Users" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Staff Users</h1>
                    <p className="text-sm text-slate-500 mt-1">{users.length} team member{users.length !== 1 ? 's' : ''}</p>
                </div>
                <button onClick={openCreate} className="btn-primary py-2 flex-shrink-0">+ Add User</button>
            </div>

            {/* Search */}
            <div className="mb-5">
                <div className="relative max-w-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none">
                        <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                    </svg>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => handleSearch(e.target.value)}
                        placeholder="Search by name or email…"
                        className="w-full pl-9 pr-4 py-2 text-sm rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent bg-white"
                    />
                    {search && (
                        <button onClick={() => handleSearch('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                {/* Mobile: card list */}
                <div className="md:hidden divide-y divide-slate-50">
                    {users.map((u) => (
                        <div key={u.id} className="p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-slate-800 truncate">{u.name}</p>
                                    <p className="text-xs text-slate-400 truncate mt-0.5">{u.email}</p>
                                </div>
                                <StatusBadge label={u.is_active ? 'Active' : 'Inactive'} variant={u.is_active ? 'green' : 'red'} />
                            </div>
                            {u.role && (
                                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                    <StatusBadge label={roleLabel(u.role)} variant={roleVariant(u.role)} />
                                    <StatusBadge
                                        label={u.location_ids.length === 0 ? 'All locations' : u.location_names.join(', ')}
                                        variant="gray"
                                        dot={false}
                                    />
                                </div>
                            )}
                            <div className="flex flex-wrap items-center gap-2 mt-3">
                                <button
                                    onClick={() => openEdit(u)}
                                    className="text-xs font-medium text-slate-600 hover:text-slate-900 px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors"
                                >
                                    Edit
                                </button>
                                <button
                                    onClick={() => openLocations(u)}
                                    className="text-xs font-medium text-slate-600 hover:text-slate-900 px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors"
                                >
                                    Locations
                                </button>
                                <button
                                    onClick={() => openPassword(u)}
                                    className="text-xs font-medium text-emerald-600 hover:text-emerald-800 px-3 py-1.5 rounded-lg border border-emerald-100 hover:bg-emerald-50 transition-colors"
                                >
                                    Password
                                </button>
                                {isOwner && (
                                    <button
                                        onClick={() => toggleActive(u)}
                                        className={`text-xs font-medium px-3 py-1.5 rounded-lg border transition-colors ${
                                            u.is_active
                                                ? 'text-amber-600 hover:text-amber-800 border-amber-100 hover:bg-amber-50'
                                                : 'text-slate-500 hover:text-slate-700 border-slate-200 hover:bg-slate-50'
                                        }`}
                                    >
                                        {u.is_active ? 'Deactivate' : 'Activate'}
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                    {users.length === 0 && (
                        <p className="px-4 py-10 text-center text-slate-400 text-sm">
                            {search
                                ? <>No users match "<span className="font-medium text-slate-600">{search}</span>".</>
                                : 'No users found.'}
                        </p>
                    )}
                </div>

                {/* Desktop: table */}
                <div className="hidden md:block overflow-x-auto">
                    <table className="w-full text-sm min-w-[560px]">
                        <thead>
                            <tr className="border-b border-slate-100">
                                <th className="table-th">Name</th>
                                <th className="table-th">Email</th>
                                <th className="table-th">Role</th>
                                <th className="table-th">Locations</th>
                                <th className="table-th">Status</th>
                                <th className="table-th">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {users.map((u) => (
                                <tr key={u.id} className="hover:bg-slate-50/60 transition-colors">
                                    <td className="table-td font-medium text-slate-800">{u.name}</td>
                                    <td className="table-td text-slate-500">{u.email}</td>
                                    <td className="table-td">
                                        {u.role && <StatusBadge label={roleLabel(u.role)} variant={roleVariant(u.role)} />}
                                    </td>
                                    <td className="table-td text-slate-500 text-xs">
                                        {u.location_ids.length === 0 ? 'All locations' : u.location_names.join(', ')}
                                    </td>
                                    <td className="table-td">
                                        <StatusBadge label={u.is_active ? 'Active' : 'Inactive'} variant={u.is_active ? 'green' : 'red'} />
                                    </td>
                                    <td className="table-td">
                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() => openEdit(u)}
                                                className="text-xs font-medium text-slate-600 hover:text-slate-900 px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                onClick={() => openLocations(u)}
                                                className="text-xs font-medium text-slate-600 hover:text-slate-900 px-2.5 py-1 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors"
                                            >
                                                Locations
                                            </button>
                                            <button
                                                onClick={() => openPassword(u)}
                                                className="text-xs font-medium text-emerald-600 hover:text-emerald-800 px-2.5 py-1 rounded-lg border border-emerald-100 hover:bg-emerald-50 transition-colors"
                                            >
                                                Password
                                            </button>
                                            {isOwner && (
                                                <button
                                                    onClick={() => toggleActive(u)}
                                                    className={`text-xs font-medium px-2.5 py-1 rounded-lg border transition-colors ${
                                                        u.is_active
                                                            ? 'text-amber-600 hover:text-amber-800 border-amber-100 hover:bg-amber-50'
                                                            : 'text-slate-500 hover:text-slate-700 border-slate-200 hover:bg-slate-50'
                                                    }`}
                                                >
                                                    {u.is_active ? 'Deactivate' : 'Activate'}
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {users.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-6 py-10 text-center text-slate-400 text-sm">
                                        {search
                                            ? <>No users match "<span className="font-medium text-slate-600">{search}</span>".</>
                                            : 'No users found.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* ── Create / Edit Modal ───────────────────────────────────── */}
            <Modal show={modalMode === 'create' || modalMode === 'edit'} onClose={closeModal} maxWidth="md">
                <div className="p-6">
                    <div className="flex items-center justify-between mb-5">
                        <h2 className="text-lg font-semibold text-slate-900">{modalMode === 'create' ? 'Add User' : 'Edit User'}</h2>
                        <CloseBtn />
                    </div>
                    <form onSubmit={submitUserForm} className="space-y-4">
                        <div>
                            <label className="form-label">Name</label>
                            <input type="text" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} className="form-input" required />
                            {editForm.errors.name && <p className="text-red-500 text-xs mt-1">{editForm.errors.name}</p>}
                        </div>
                        <div>
                            <label className="form-label">Email</label>
                            <input type="email" value={editForm.data.email} onChange={(e) => editForm.setData('email', e.target.value)} className="form-input" required />
                            {editForm.errors.email && <p className="text-red-500 text-xs mt-1">{editForm.errors.email}</p>}
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="form-label">Role</label>
                                <select
                                    value={editForm.data.role}
                                    onChange={(e) => editForm.setData('role', e.target.value)}
                                    className="form-input"
                                >
                                    {roles.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
                                </select>
                                {editForm.errors.role && <p className="text-red-500 text-xs mt-1">{editForm.errors.role}</p>}
                            </div>
                            <div>
                                <label className="form-label">
                                    POS PIN {modalMode === 'edit' && <span className="text-slate-400 font-normal">(leave blank to keep)</span>}
                                </label>
                                <input
                                    type="text"
                                    maxLength={4}
                                    value={editForm.data.pin}
                                    onChange={(e) => editForm.setData('pin', e.target.value)}
                                    className="form-input"
                                    placeholder="••••"
                                    required={modalMode === 'create'}
                                />
                                {editForm.errors.pin && <p className="text-red-500 text-xs mt-1">{editForm.errors.pin}</p>}
                            </div>
                        </div>
                        {modalMode === 'edit' && (
                            <div className="flex items-center gap-3 p-3 rounded-xl border border-slate-100 bg-slate-50">
                                <input
                                    id="bo_is_active"
                                    type="checkbox"
                                    checked={editForm.data.is_active}
                                    onChange={(e) => editForm.setData('is_active', e.target.checked)}
                                    className="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 flex-shrink-0"
                                />
                                <label htmlFor="bo_is_active" className="text-sm font-medium text-slate-700 cursor-pointer">
                                    Account active
                                    <span className="block sm:inline sm:ml-2 text-xs font-normal text-slate-400">
                                        Inactive users cannot log in to the POS
                                    </span>
                                </label>
                            </div>
                        )}
                        {modalMode === 'create' && (
                            <div className="rounded-xl bg-sky-50 border border-sky-100 px-4 py-3">
                                <p className="text-xs text-sky-700">
                                    They can sign in at the till once this syncs. Back Office (browser) access needs a
                                    password set separately — use "Password" from the list after creating them.
                                </p>
                            </div>
                        )}
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={editForm.processing} className="btn-primary disabled:opacity-50">
                                {modalMode === 'create' ? 'Create User' : 'Save Changes'}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* ── Change Password Modal ──────────────────────────────────── */}
            <Modal show={modalMode === 'password'} onClose={closeModal} maxWidth="sm">
                <div className="p-6">
                    <div className="flex items-center justify-between mb-1">
                        <h2 className="text-lg font-semibold text-slate-900">Change Password</h2>
                        <CloseBtn />
                    </div>
                    {selected && (
                        <p className="text-sm text-slate-500 mb-5">Setting new password for <span className="font-medium text-slate-700">{selected.name}</span></p>
                    )}
                    <form onSubmit={submitPassword} className="space-y-4">
                        <div>
                            <label className="form-label">New Password</label>
                            <input
                                type="password"
                                value={pwForm.data.password}
                                onChange={(e) => pwForm.setData('password', e.target.value)}
                                className="form-input"
                                required
                                autoComplete="new-password"
                            />
                            {pwForm.errors.password && <p className="text-red-500 text-xs mt-1">{pwForm.errors.password}</p>}
                        </div>
                        <div>
                            <label className="form-label">Confirm Password</label>
                            <input
                                type="password"
                                value={pwForm.data.password_confirmation}
                                onChange={(e) => pwForm.setData('password_confirmation', e.target.value)}
                                className="form-input"
                                required
                                autoComplete="new-password"
                            />
                            {pwForm.errors.password_confirmation && <p className="text-red-500 text-xs mt-1">{pwForm.errors.password_confirmation}</p>}
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={pwForm.processing} className="btn-primary disabled:opacity-50">Update Password</button>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* ── Locations Modal ────────────────────────────────────────── */}
            <Modal show={modalMode === 'locations'} onClose={closeModal} maxWidth="sm">
                <div className="p-6">
                    <div className="flex items-center justify-between mb-1">
                        <h2 className="text-lg font-semibold text-slate-900">Location Access</h2>
                        <CloseBtn />
                    </div>
                    {selected && (
                        <p className="text-sm text-slate-500 mb-5">
                            Restrict <span className="font-medium text-slate-700">{selected.name}</span> to specific
                            locations. Leave everything unchecked for unrestricted access to every location.
                        </p>
                    )}
                    <form onSubmit={submitLocations} className="space-y-4">
                        <div className="space-y-2 max-h-64 overflow-y-auto">
                            {locations.map((loc) => (
                                <label key={loc.id} className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={locForm.data.location_ids.includes(loc.id)}
                                        onChange={() => toggleLocationId(loc.id)}
                                        className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    {loc.name}
                                </label>
                            ))}
                            {locations.length === 0 && (
                                <p className="text-sm text-slate-400">No locations yet.</p>
                            )}
                        </div>
                        {locForm.errors.location_ids && <p className="text-red-500 text-xs mt-1">{locForm.errors.location_ids}</p>}
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={locForm.processing} className="btn-primary disabled:opacity-50">Save</button>
                        </div>
                    </form>
                </div>
            </Modal>
        </BackOfficeLayout>
    );
}
