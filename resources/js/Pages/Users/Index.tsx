import React, { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';
import BusinessTabs from '@/Components/BusinessTabs';

interface User {
    id: number;
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
}

function roleVariant(role: string | null): 'blue' | 'amber' | 'gray' {
    if (role === 'business_owner') return 'blue';
    if (role === 'manager')        return 'amber';
    return 'gray';
}

export default function UsersIndex({ business, users, roles }: Props) {
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name:     '',
        email:    '',
        password: '',
        role:     'cashier',
        pin:      '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/businesses/${business.id}/users`, {
            onSuccess: () => { reset(); setShowForm(false); },
        });
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

            <div className="flex items-center justify-between mb-6">
                <h2 className="text-lg font-semibold text-slate-800">Staff Users</h2>
                <button onClick={() => setShowForm(!showForm)} className="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                    Add User
                </button>
            </div>

            {showForm && (
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 mb-6 max-w-lg">
                    <h3 className="font-semibold text-slate-800 mb-5">New Staff User</h3>
                    <form onSubmit={submit} className="space-y-4">
                        {(['name', 'email', 'password'] as const).map((field) => (
                            <div key={field}>
                                <label className="form-label capitalize">{field}</label>
                                <input
                                    type={field === 'password' ? 'password' : field === 'email' ? 'email' : 'text'}
                                    value={data[field]}
                                    onChange={(e) => setData(field, e.target.value)}
                                    className="form-input"
                                    required
                                />
                                {errors[field] && <p className="text-red-500 text-xs mt-1.5">{errors[field]}</p>}
                            </div>
                        ))}
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="form-label">Role</label>
                                <select
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    className="form-input"
                                >
                                    {roles.map((r) => <option key={r} value={r}>{r.replace('_', ' ')}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="form-label">POS PIN <span className="text-slate-400 font-normal">(4 digits)</span></label>
                                <input
                                    type="text"
                                    maxLength={4}
                                    value={data.pin}
                                    onChange={(e) => setData('pin', e.target.value)}
                                    className="form-input"
                                    placeholder="0000"
                                />
                            </div>
                        </div>
                        <div className="flex justify-end gap-3 pt-2">
                            <button type="button" onClick={() => setShowForm(false)} className="btn-secondary">Cancel</button>
                            <button type="submit" disabled={processing} className="btn-primary disabled:opacity-50">Create User</button>
                        </div>
                    </form>
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm">
                <table className="w-full text-sm">
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
                                        <StatusBadge
                                            label={u.role.replace('_', ' ')}
                                            variant={roleVariant(u.role)}
                                        />
                                    )}
                                </td>
                                <td className="table-td">
                                    <StatusBadge label={u.is_active ? 'Active' : 'Inactive'} variant={u.is_active ? 'green' : 'red'} />
                                </td>
                                <td className="table-td">
                                    <button
                                        onClick={() => {
                                            if (confirm(`Remove ${u.name}? This cannot be undone.`)) {
                                                router.delete(`/businesses/${business.id}/users/${u.id}`);
                                            }
                                        }}
                                        className="text-xs font-medium text-red-500 hover:text-red-700 px-2.5 py-1 rounded-lg border border-red-100 hover:bg-red-50 transition-colors"
                                    >
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {users.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-6 py-10 text-center text-slate-400 text-sm">
                                    No staff users yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
