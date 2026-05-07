import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatsCard from '@/Components/StatsCard';
import StatusBadge from '@/Components/StatusBadge';

interface Business {
    id: string;
    business_name: string;
    owner_email: string;
    tier: 'starter' | 'pro' | 'ultimate';
    is_active: boolean;
    devices_count: number;
}

interface Props {
    stats: {
        total_businesses: number;
        active_devices: number;
        pro_businesses: number;
        ultimate_businesses: number;
    };
    recent_businesses: Business[];
}

const tierBadge = (tier: string) => {
    if (tier === 'ultimate') return <StatusBadge label="Ultimate" variant="violet" />;
    if (tier === 'pro')      return <StatusBadge label="Pro" variant="amber" />;
    return <StatusBadge label="Starter" variant="gray" />;
};

const IconBuilding = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M4 16.5v-13h-.25a.75.75 0 0 1 0-1.5h12.5a.75.75 0 0 1 0 1.5H16v13h.25a.75.75 0 0 1 0 1.5h-3.5a.75.75 0 0 1-.75-.75v-2.5a.75.75 0 0 0-.75-.75h-2.5a.75.75 0 0 0-.75.75v2.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5H4Zm3-11a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1Zm4 0a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5v-1ZM7.5 9a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1Zm3 0a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1Z" clipRule="evenodd" />
    </svg>
);

const IconDevice = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M5 1a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V4a3 3 0 0 0-3-3H5Zm0 3h10v9H5V4Zm5 11a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clipRule="evenodd" />
    </svg>
);

const IconStar = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401Z" clipRule="evenodd" />
    </svg>
);

const IconCrown = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path d="M15.98 1.804a1 1 0 0 0-1.96 0l-.24 1.192a1 1 0 0 1-.784.785l-1.192.238a1 1 0 0 0 0 1.962l1.192.238a1 1 0 0 1 .785.785l.238 1.192a1 1 0 0 0 1.962 0l.238-1.192a1 1 0 0 1 .785-.785l1.192-.238a1 1 0 0 0 0-1.962l-1.192-.238a1 1 0 0 1-.785-.785l-.238-1.192ZM6.949 5.684a1 1 0 0 0-1.898 0l-.683 2.051a1 1 0 0 1-.633.633l-2.051.683a1 1 0 0 0 0 1.898l2.051.684a1 1 0 0 1 .633.632l.683 2.051a1 1 0 0 0 1.898 0l.683-2.051a1 1 0 0 1 .633-.633l2.051-.683a1 1 0 0 0 0-1.898l-2.051-.683a1 1 0 0 1-.633-.633L6.95 5.684Z" />
    </svg>
);

export default function Dashboard({ stats, recent_businesses }: Props) {
    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="mb-8">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Dashboard</h1>
                <p className="text-sm text-slate-500 mt-1">SmartPOS cloud portal overview</p>
            </div>

            <div className="grid grid-cols-2 gap-4 mb-8 lg:grid-cols-4">
                <StatsCard label="Total Businesses" value={stats.total_businesses} color="blue"   icon={<IconBuilding />} />
                <StatsCard label="Active Devices"   value={stats.active_devices}   color="green"  icon={<IconDevice />} />
                <StatsCard label="Pro Subscribers"  value={stats.pro_businesses}   color="amber"  icon={<IconStar />} />
                <StatsCard label="Ultimate"         value={stats.ultimate_businesses} color="violet" icon={<IconCrown />} />
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-x-auto">
                <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h2 className="font-semibold text-slate-800">Recent Businesses</h2>
                    <Link href="/businesses" className="text-sm text-violet-600 font-medium hover:text-violet-700">
                        View all →
                    </Link>
                </div>
                <table className="w-full text-sm min-w-[540px]">
                    <thead>
                        <tr className="text-left border-b border-slate-100">
                            <th className="table-th">Business</th>
                            <th className="table-th">Owner</th>
                            <th className="table-th">Tier</th>
                            <th className="table-th">Devices</th>
                            <th className="table-th">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {recent_businesses.map((b) => (
                            <tr key={b.id} className="hover:bg-slate-50/60 transition-colors">
                                <td className="table-td">
                                    <Link href={`/businesses/${b.id}`} className="font-medium text-violet-700 hover:text-violet-900">
                                        {b.business_name}
                                    </Link>
                                </td>
                                <td className="table-td text-slate-500">{b.owner_email}</td>
                                <td className="table-td">{tierBadge(b.tier)}</td>
                                <td className="table-td text-slate-500">{b.devices_count}</td>
                                <td className="table-td">
                                    <StatusBadge label={b.is_active ? 'Active' : 'Inactive'} variant={b.is_active ? 'green' : 'red'} />
                                </td>
                            </tr>
                        ))}
                        {recent_businesses.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-6 py-10 text-center text-slate-400">
                                    No businesses yet.{' '}
                                    <Link href="/businesses/create" className="text-violet-600 hover:underline">
                                        Create one
                                    </Link>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
