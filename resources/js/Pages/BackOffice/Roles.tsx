import React, { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface PermissionOption {
    key: string;
    label: string;
}

interface RoleRow {
    name: string;
    is_builtin: boolean;
    permissions: string[];
}

interface Props {
    roles: RoleRow[];
    permission_catalogue: PermissionOption[];
}

function roleLabel(role: string): string {
    return role.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function BackOfficeRoles({ roles, permission_catalogue }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const [showCreate, setShowCreate] = useState(false);

    const createForm = useForm({ name: '' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post('/office/roles', {
            preserveScroll: true,
            onSuccess: () => {
                setShowCreate(false);
                createForm.reset();
            },
        });
    };

    const togglePermission = (role: RoleRow, permission: string) => {
        const next = role.permissions.includes(permission)
            ? role.permissions.filter((p) => p !== permission)
            : [...role.permissions, permission];

        router.put(`/office/roles/${role.name}`, { permissions: next }, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Roles & Permissions" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Roles & Permissions</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Business Owner always has full access. Customize what Manager and Cashier can do here, or create
                        an entirely new role for Back Office-only staff (e.g. an Auditor or Warehouse Clerk).
                    </p>
                </div>
                <button onClick={() => setShowCreate(true)} className="btn-primary py-2 flex-shrink-0">+ New Role</button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            {showCreate && (
                <form onSubmit={submitCreate} className="mb-6 bg-white rounded-2xl border border-slate-100 shadow-sm p-5 flex items-end gap-3">
                    <div className="flex-1">
                        <label className="text-xs font-semibold text-slate-500">Role name</label>
                        <input
                            type="text"
                            value={createForm.data.name}
                            onChange={(e) => createForm.setData('name', e.target.value)}
                            placeholder="e.g. warehouse_clerk"
                            className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                        {createForm.errors.name && <p className="text-xs text-red-500 mt-1">{createForm.errors.name}</p>}
                    </div>
                    <button type="submit" disabled={createForm.processing} className="btn-primary py-2">Create</button>
                    <button type="button" onClick={() => setShowCreate(false)} className="text-xs font-semibold text-slate-400 hover:text-slate-600 py-2">
                        Cancel
                    </button>
                </form>
            )}

            <div className="space-y-4">
                {roles.map((role) => (
                    <div key={role.name} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                        <div className="flex items-center gap-2 mb-4">
                            <p className="text-sm font-semibold text-slate-900">{roleLabel(role.name)}</p>
                            {role.is_builtin && <StatusBadge label="Built-in" variant="gray" dot={false} />}
                        </div>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            {permission_catalogue.map((perm) => (
                                <label key={perm.key} className="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={role.permissions.includes(perm.key)}
                                        onChange={() => togglePermission(role, perm.key)}
                                        className="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                    />
                                    {perm.label}
                                </label>
                            ))}
                        </div>
                    </div>
                ))}
                {roles.length === 0 && (
                    <p className="text-sm text-slate-400 text-center py-8">No roles yet.</p>
                )}
            </div>
        </BackOfficeLayout>
    );
}
