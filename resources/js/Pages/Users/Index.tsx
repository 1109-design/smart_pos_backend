import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';
import BusinessTabs from '@/Components/BusinessTabs';
import Modal from '@/Components/Modal';

interface User {
    id: string;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
}

interface Business {
    id: string;
    business_name: string;
}

interface Props {
    business: Business;
    users: User[];
    roles: string[];
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

type ModalMode = 'create' | 'edit' | 'password' | null;

export default function UsersIndex({ business, users, roles, filters }: Props) {
    const [modalMode, setModalMode] = useState<ModalMode>(null);
    const [selectedUser, setSelectedUser] = useState<User | null>(null);
    const [search, setSearch] = useState(filters.search);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleSearch = useCallback((value: string) => {
        setSearch(value);
        if (debounceRef.current) { clearTimeout(debounceRef.current); }
        debounceRef.current = setTimeout(() => {
            router.get(`/businesses/${business.id}/users`, { search: value }, { preserveState: true, replace: true });
        }, 350);
    }, [business.id]);

    useEffect(() => () => { if (debounceRef.current) { clearTimeout(debounceRef.current); } }, []);

    const createForm = useForm({ name: '', email: '', password: '', role: 'cashier', pin: '' });
    const editForm   = useForm({ name: '', email: '', role: 'cashier', is_active: true as boolean, pin: '' });
    const pwForm     = useForm({ password: '', password_confirmation: '' });

    const openCreate = () => {
        createForm.reset();
        setModalMode('create');
    };

    const openEdit = (u: User) => {
        setSelectedUser(u);
        editForm.setData({ name: u.name, email: u.email, role: u.role ?? 'cashier', is_active: u.is_active, pin: '' });
        setModalMode('edit');
    };

    const openPassword = (u: User) => {
        setSelectedUser(u);
        pwForm.reset();
        setModalMode('password');
    };

    const closeModal = () => { setModalMode(null); setSelectedUser(null); };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post(`/businesses/${business.id}/users`, { onSuccess: closeModal });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        editForm.put(`/businesses/${business.id}/users/${selectedUser!.id}`, { onSuccess: closeModal });
    };

    const submitPassword = (e: React.FormEvent) => {
        e.preventDefault();
        pwForm.put(`/businesses/${business.id}/users/${selectedUser!.id}/password`, { onSuccess: closeModal });
    };

    const confirmDelete = (u: User) => {
        if (confirm(`Remove ${u.name}? This cannot be undone.`)) {
            router.delete(`/businesses/${business.id}/users/${u.id}`);
        }
    };

    return (
        <AppLayout>
            <Head title={`Users — ${business.business_name}`} />

            <div className="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <Link href="/businesses" className="hover:text-slate-600 transition-colors">Businesses</Link>
                <span>/</span>
                <Link href={`/businesses/${business.id}`} className="hover:text-slate-600 transition-colors">
                    {business.business_name}
                </Link>
                <span>/</span>
                <span className="text-slate-600 font-medium">Staff Users</span>
            </div>

            <div className="flex items-start justify-between mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{business.business_name}</h1>
            </div>

            <BusinessTabs businessId={business.id} active="users" />

            <div className="flex items-center justify-between mb-5">
                {/* Search */}
                <div className="relative w-72">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none">
                        <path fillRule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clipRule="evenodd" />
                    </svg>
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => handleSearch(e.target.value)}
                        placeholder="Search by name or email…"
                        className="w-full pl-9 pr-4 py-2 text-sm rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:border-transparent bg-white"
                    />
                    {search && (
                        <button onClick={() => handleSearch('')} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    )}
                </div>

                <button onClick={openCreate} className="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                    Add User
                </button>
            </div>

            {/* Table */}
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-x-auto -mx-4 sm:mx-0 rounded-none sm:rounded-2xl">
                <table className="w-full text-sm min-w-[600px]">
                    <thead>
                        <tr className="border-b border-slate-100">
                            <th className="table-th">Name</th>
                            <th className="table-th">Email</th>
                            <th className="table-th">Role</th>
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
                                    {u.role && (
                                        <StatusBadge label={roleLabel(u.role)} variant={roleVariant(u.role)} />
                                    )}
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
                                            onClick={() => openPassword(u)}
                                            className="text-xs font-medium text-violet-600 hover:text-violet-800 px-2.5 py-1 rounded-lg border border-violet-100 hover:bg-violet-50 transition-colors"
                                        >
                                            Password
                                        </button>
                                        <button
                                            onClick={() => confirmDelete(u)}
                                            className="text-xs font-medium text-red-500 hover:text-red-700 px-2.5 py-1 rounded-lg border border-red-100 hover:bg-red-50 transition-colors"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {users.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-6 py-10 text-center text-slate-400 text-sm">
                                    {search
                                        ? <>No users match "<span className="font-medium text-slate-600">{search}</span>".</>
                                        : 'No staff users yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* ── Create Modal ──────────────────────────────────────────── */}
            <Modal show={modalMode === 'create'} onClose={closeModal} maxWidth="md">
                <div className="p-6">
                    <div className="flex items-center justify-between mb-5">
                        <h2 className="text-lg font-semibold text-slate-900">New Staff User</h2>
                        <button onClick={closeModal} className="text-slate-400 hover:text-slate-600">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div>
                            <label className="form-label">Name</label>
                            <input type="text" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} className="form-input" required />
                            {createForm.errors.name && <p className="text-red-500 text-xs mt-1">{createForm.errors.name}</p>}
                        </div>
                        <div>
                            <label className="form-label">Email</label>
                            <input type="email" value={createForm.data.email} onChange={(e) => createForm.setData('email', e.target.value)} className="form-input" required />
                            {createForm.errors.email && <p className="text-red-500 text-xs mt-1">{createForm.errors.email}</p>}
                        </div>
                        <div>
                            <label className="form-label">Password</label>
                            <input type="password" value={createForm.data.password} onChange={(e) => createForm.setData('password', e.target.value)} className="form-input" required />
                            {createForm.errors.password && <p className="text-red-500 text-xs mt-1">{createForm.errors.password}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="form-label">Role</label>
                                <select value={createForm.data.role} onChange={(e) => createForm.setData('role', e.target.value)} className="form-input">
                                    {roles.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
                                </select>
                                {createForm.errors.role && <p className="text-red-500 text-xs mt-1">{createForm.errors.role}</p>}
                            </div>
                            <div>
                                <label className="form-label">POS PIN <span className="text-slate-400 font-normal">(4 digits)</span></label>
                                <input type="text" maxLength={4} value={createForm.data.pin} onChange={(e) => createForm.setData('pin', e.target.value)} className="form-input" placeholder="0000" />
                                {createForm.errors.pin && <p className="text-red-500 text-xs mt-1">{createForm.errors.pin}</p>}
                            </div>
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={createForm.processing} className="btn-primary disabled:opacity-50">Create User</button>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* ── Edit Modal ────────────────────────────────────────────── */}
            <Modal show={modalMode === 'edit'} onClose={closeModal} maxWidth="md">
                <div className="p-6">
                    <div className="flex items-center justify-between mb-5">
                        <h2 className="text-lg font-semibold text-slate-900">Edit User</h2>
                        <button onClick={closeModal} className="text-slate-400 hover:text-slate-600">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submitEdit} className="space-y-4">
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
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="form-label">Role</label>
                                <select value={editForm.data.role} onChange={(e) => editForm.setData('role', e.target.value)} className="form-input">
                                    {roles.map((r) => <option key={r} value={r}>{roleLabel(r)}</option>)}
                                </select>
                                {editForm.errors.role && <p className="text-red-500 text-xs mt-1">{editForm.errors.role}</p>}
                            </div>
                            <div>
                                <label className="form-label">POS PIN <span className="text-slate-400 font-normal">(leave blank to keep)</span></label>
                                <input type="text" maxLength={4} value={editForm.data.pin} onChange={(e) => editForm.setData('pin', e.target.value)} className="form-input" placeholder="••••" />
                                {editForm.errors.pin && <p className="text-red-500 text-xs mt-1">{editForm.errors.pin}</p>}
                            </div>
                        </div>
                        <div className="flex items-center gap-3 p-3 rounded-xl border border-slate-100 bg-slate-50">
                            <input
                                id="is_active"
                                type="checkbox"
                                checked={editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                                className="w-4 h-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"
                            />
                            <label htmlFor="is_active" className="text-sm font-medium text-slate-700 cursor-pointer">
                                Account active
                            </label>
                            <span className="text-xs text-slate-400 ml-auto">Inactive users cannot log in to the POS</span>
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={editForm.processing} className="btn-primary disabled:opacity-50">Save Changes</button>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* ── Change Password Modal ─────────────────────────────────── */}
            <Modal show={modalMode === 'password'} onClose={closeModal} maxWidth="sm">
                <div className="p-6">
                    <div className="flex items-center justify-between mb-1">
                        <h2 className="text-lg font-semibold text-slate-900">Change Password</h2>
                        <button onClick={closeModal} className="text-slate-400 hover:text-slate-600">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </div>
                    {selectedUser && (
                        <p className="text-sm text-slate-500 mb-5">Setting new password for <span className="font-medium text-slate-700">{selectedUser.name}</span></p>
                    )}
                    <form onSubmit={submitPassword} className="space-y-4">
                        <div>
                            <label className="form-label">New Password</label>
                            <input type="password" value={pwForm.data.password} onChange={(e) => pwForm.setData('password', e.target.value)} className="form-input" required autoComplete="new-password" />
                            {pwForm.errors.password && <p className="text-red-500 text-xs mt-1">{pwForm.errors.password}</p>}
                        </div>
                        <div>
                            <label className="form-label">Confirm Password</label>
                            <input type="password" value={pwForm.data.password_confirmation} onChange={(e) => pwForm.setData('password_confirmation', e.target.value)} className="form-input" required autoComplete="new-password" />
                            {pwForm.errors.password_confirmation && <p className="text-red-500 text-xs mt-1">{pwForm.errors.password_confirmation}</p>}
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={closeModal} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={pwForm.processing} className="btn-primary disabled:opacity-50">Update Password</button>
                        </div>
                    </form>
                </div>
            </Modal>
        </AppLayout>
    );
}
