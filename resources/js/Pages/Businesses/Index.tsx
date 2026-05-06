import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';

interface Business {
    id: string;
    business_name: string;
    owner_email: string;
    tier: 'starter' | 'pro' | 'ultimate';
    is_active: boolean;
    devices_count: number;
    created_at: string;
}

interface Paginated {
    data: Business[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    businesses: Paginated;
    filters: { search: string };
}

const tierVariant = (tier: string): 'violet' | 'amber' | 'gray' => {
    if (tier === 'ultimate') return 'violet';
    if (tier === 'pro')      return 'amber';
    return 'gray';
};

export default function BusinessesIndex({ businesses, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const handleSearch = useCallback((value: string) => {
        setSearch(value);
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }
        debounceRef.current = setTimeout(() => {
            router.get('/businesses', { search: value }, { preserveState: true, replace: true });
        }, 350);
    }, []);

    useEffect(() => () => { if (debounceRef.current) { clearTimeout(debounceRef.current); } }, []);

    return (
        <AppLayout>
            <Head title="Businesses" />

            <div className="flex items-center justify-between mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Businesses</h1>
                    <p className="text-sm text-slate-500 mt-1">{businesses.data.length} registered businesses</p>
                </div>
                <Link href="/businesses/create" className="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                    New Business
                </Link>
            </div>

            <div className="mb-4">
                <div className="relative max-w-sm">
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
                        <button
                            onClick={() => handleSearch('')}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-100">
                            <th className="table-th">Business</th>
                            <th className="table-th">Owner</th>
                            <th className="table-th">Tier</th>
                            <th className="table-th">Devices</th>
                            <th className="table-th">Status</th>
                            <th className="table-th">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {businesses.data.map((b) => (
                            <tr key={b.id} className="hover:bg-slate-50/60 transition-colors">
                                <td className="table-td">
                                    <Link href={`/businesses/${b.id}`} className="font-semibold text-violet-700 hover:text-violet-900">
                                        {b.business_name}
                                    </Link>
                                </td>
                                <td className="table-td text-slate-500">{b.owner_email}</td>
                                <td className="table-td">
                                    <StatusBadge
                                        label={b.tier.charAt(0).toUpperCase() + b.tier.slice(1)}
                                        variant={tierVariant(b.tier)}
                                    />
                                </td>
                                <td className="table-td text-slate-500">{b.devices_count}</td>
                                <td className="table-td">
                                    <StatusBadge label={b.is_active ? 'Active' : 'Inactive'} variant={b.is_active ? 'green' : 'red'} />
                                </td>
                                <td className="table-td">
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href={`/businesses/${b.id}/edit`}
                                            className="text-xs font-medium text-slate-500 hover:text-slate-800 px-2.5 py-1 rounded-lg border border-slate-200 hover:border-slate-300 transition-colors"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            onClick={() => {
                                                if (confirm(`Delete "${b.business_name}"? This cannot be undone.`)) {
                                                    router.delete(`/businesses/${b.id}`);
                                                }
                                            }}
                                            className="text-xs font-medium text-red-500 hover:text-red-700 px-2.5 py-1 rounded-lg border border-red-100 hover:border-red-200 hover:bg-red-50 transition-colors"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {businesses.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-6 py-12 text-center">
                                    {search ? (
                                        <p className="text-slate-400 text-sm">No businesses match "<span className="font-medium text-slate-600">{search}</span>".</p>
                                    ) : (
                                        <>
                                            <p className="text-slate-400 text-sm">No businesses registered yet.</p>
                                            <Link href="/businesses/create" className="mt-2 inline-block text-sm text-violet-600 font-medium hover:underline">
                                                Create your first business →
                                            </Link>
                                        </>
                                    )}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {businesses.last_page > 1 && (
                    <div className="flex items-center justify-between px-6 py-4 border-t border-slate-100 text-sm">
                        <span className="text-slate-400 text-xs">Page {businesses.current_page} of {businesses.last_page}</span>
                        <div className="flex gap-2">
                            {businesses.prev_page_url && (
                                <Link href={businesses.prev_page_url} className="btn-secondary py-1 text-xs">← Previous</Link>
                            )}
                            {businesses.next_page_url && (
                                <Link href={businesses.next_page_url} className="btn-secondary py-1 text-xs">Next →</Link>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
