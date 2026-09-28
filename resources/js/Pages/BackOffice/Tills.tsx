import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface LocationOption {
    id: string;
    name: string;
    type: 'shop' | 'warehouse';
}

interface TillRow {
    id: string;
    name: string;
    register_number: number;
    is_active: boolean;
    location_id: string;
    location: LocationOption | null;
    last_moved_at: string | null;
    last_moved_by_user_name: string | null;
}

interface Props {
    tills: TillRow[];
    locations: LocationOption[];
}

export default function BackOfficeTills({ tills, locations }: Props) {
    const { flash, errors } = usePage().props as unknown as {
        flash: { success: string | null };
        errors: Record<string, string>;
    };

    const [pendingMove, setPendingMove] = useState<{ tillId: string; locationId: string } | null>(null);

    const moveTill = (tillId: string, locationId: string) => {
        if (!locationId) return;
        setPendingMove({ tillId, locationId });
        router.put(`/office/tills/${tillId}/location`, { location_id: locationId }, {
            preserveScroll: true,
            onFinish: () => setPendingMove(null),
        });
    };

    return (
        <BackOfficeLayout>
            <Head title="Tills" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Tills</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Every register across every branch. Moving a till to a new location takes effect on devices at their next sync
                    — it's blocked while the till has an open shift.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}
            {errors?.location_id && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {errors.location_id}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-100 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">
                            <th className="px-5 py-3">Till</th>
                            <th className="px-5 py-3">Register #</th>
                            <th className="px-5 py-3">Current location</th>
                            <th className="px-5 py-3">Last moved</th>
                            <th className="px-5 py-3">Status</th>
                            <th className="px-5 py-3">Move to</th>
                        </tr>
                    </thead>
                    <tbody>
                        {tills.map((till) => (
                            <tr key={till.id} className={`border-b border-slate-50 last:border-0 ${!till.is_active ? 'opacity-50' : ''}`}>
                                <td className="px-5 py-3 font-medium text-slate-800">{till.name}</td>
                                <td className="px-5 py-3 text-slate-500">{till.register_number}</td>
                                <td className="px-5 py-3 text-slate-600">{till.location?.name ?? '—'}</td>
                                <td className="px-5 py-3 text-slate-500">
                                    {till.last_moved_at
                                        ? `${new Date(till.last_moved_at).toLocaleDateString()} by ${till.last_moved_by_user_name ?? 'Unknown'}`
                                        : '—'}
                                </td>
                                <td className="px-5 py-3">
                                    {till.is_active
                                        ? <StatusBadge label="Active" variant="green" dot={false} />
                                        : <StatusBadge label="Inactive" variant="gray" />}
                                </td>
                                <td className="px-5 py-3">
                                    <select
                                        defaultValue=""
                                        disabled={pendingMove?.tillId === till.id}
                                        onChange={(e) => moveTill(till.id, e.target.value)}
                                        className="text-xs rounded-lg border border-slate-200 px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:opacity-50"
                                    >
                                        <option value="">Move to…</option>
                                        {locations
                                            .filter((location) => location.id !== till.location_id)
                                            .map((location) => (
                                                <option key={location.id} value={location.id}>
                                                    {location.name} ({location.type})
                                                </option>
                                            ))}
                                    </select>
                                </td>
                            </tr>
                        ))}
                        {tills.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-5 py-8 text-center text-sm text-slate-400">
                                    No tills yet — they're created automatically the first time a device opens a shift.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </BackOfficeLayout>
    );
}
