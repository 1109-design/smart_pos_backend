import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';
import BusinessTabs from '@/Components/BusinessTabs';

interface Device {
    id: number;
    name: string;
    device_identifier: string;
    last_seen_at: string | null;
    is_revoked: boolean;
    created_at: string;
}

interface Business {
    id: string;
    business_name: string;
}

interface Props {
    business: Business;
    devices: Device[];
}

export default function DevicesIndex({ business, devices }: Props) {
    const revoke = (device: Device) => {
        if (confirm(`Revoke device "${device.name}"? The POS app will be logged out.`)) {
            router.post(`/businesses/${business.id}/devices/${device.id}/revoke`);
        }
    };

    const remove = (device: Device) => {
        if (confirm(`Remove device "${device.name}"? This cannot be undone.`)) {
            router.delete(`/businesses/${business.id}/devices/${device.id}`);
        }
    };

    return (
        <AppLayout>
            <Head title={`Devices — ${business.business_name}`} />

            <div className="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <Link href="/businesses" className="hover:text-slate-600 transition-colors">Businesses</Link>
                <span>/</span>
                <Link href={`/businesses/${business.id}`} className="hover:text-slate-600 transition-colors">
                    {business.business_name}
                </Link>
                <span>/</span>
                <span className="text-slate-600 font-medium">Devices</span>
            </div>

            <div className="flex items-start justify-between mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{business.business_name}</h1>
            </div>

            <BusinessTabs businessId={business.id} active="devices" devicesCount={devices.length} />

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-x-auto">
                <table className="w-full text-sm min-w-[600px]">
                    <thead>
                        <tr className="border-b border-slate-100">
                            <th className="table-th">Device Name</th>
                            <th className="table-th">Identifier</th>
                            <th className="table-th">Last Seen</th>
                            <th className="table-th">Registered</th>
                            <th className="table-th">Status</th>
                            <th className="table-th">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {devices.map((d) => (
                            <tr key={d.id} className="hover:bg-slate-50/60 transition-colors">
                                <td className="table-td font-medium text-slate-800">{d.name}</td>
                                <td className="table-td font-mono text-xs text-slate-400">{d.device_identifier}</td>
                                <td className="table-td text-slate-500">{d.last_seen_at ?? 'Never'}</td>
                                <td className="table-td text-slate-500">{d.created_at}</td>
                                <td className="table-td">
                                    <StatusBadge label={d.is_revoked ? 'Revoked' : 'Active'} variant={d.is_revoked ? 'red' : 'green'} />
                                </td>
                                <td className="table-td">
                                    <div className="flex items-center gap-2">
                                        {!d.is_revoked && (
                                            <button
                                                onClick={() => revoke(d)}
                                                className="text-xs font-medium text-amber-600 hover:text-amber-800 px-2.5 py-1 rounded-lg border border-amber-100 hover:bg-amber-50 transition-colors"
                                            >
                                                Revoke
                                            </button>
                                        )}
                                        <button
                                            onClick={() => remove(d)}
                                            className="text-xs font-medium text-red-500 hover:text-red-700 px-2.5 py-1 rounded-lg border border-red-100 hover:bg-red-50 transition-colors"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {devices.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-6 py-10 text-center text-slate-400 text-sm">
                                    No devices registered. Devices appear here when a POS app first logs in.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
