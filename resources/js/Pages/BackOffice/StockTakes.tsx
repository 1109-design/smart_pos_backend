import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type TakeStatus = 'draft' | 'in_progress' | 'pending_approval' | 'approved' | 'rejected' | 'cancelled';

interface StockTakeRow {
    id: string;
    title: string;
    status: TakeStatus;
    location: { id: string; name: string } | null;
    created_at: string;
    approved_at: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    stock_takes: Paginated<StockTakeRow>;
    filters: { status: string };
}

const STATUS_STYLE: Record<TakeStatus, { label: string; variant: 'amber' | 'blue' | 'violet' | 'green' | 'red' | 'gray' }> = {
    draft: { label: 'Draft', variant: 'gray' },
    in_progress: { label: 'Counting', variant: 'amber' },
    pending_approval: { label: 'Pending Review', variant: 'violet' },
    approved: { label: 'Approved', variant: 'green' },
    rejected: { label: 'Rejected', variant: 'red' },
    cancelled: { label: 'Cancelled', variant: 'gray' },
};

export default function BackOfficeStockTakes({ stock_takes, filters }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    return (
        <BackOfficeLayout>
            <Head title="Stocktakes" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Stocktakes</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Physical counts submitted from the till. Review one pending approval to adjust stock and close it out
                    without walking up to a device.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="mb-4">
                <select
                    value={filters.status}
                    onChange={(e) => router.get('/office/stocktakes', { status: e.target.value }, { preserveState: true })}
                    className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="all">All statuses</option>
                    {Object.entries(STATUS_STYLE).map(([value, s]) => (
                        <option key={value} value={value}>{s.label}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[600px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Title</th>
                                <th className="table-th">Location</th>
                                <th className="table-th">Submitted</th>
                                <th className="table-th">Status</th>
                                <th className="table-th text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {stock_takes.data.map((t) => (
                                <tr key={t.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">
                                        <Link href={`/office/stocktakes/${t.id}`} className="hover:text-emerald-700 hover:underline">
                                            {t.title}
                                        </Link>
                                    </td>
                                    <td className="table-td text-slate-600">{t.location?.name ?? '—'}</td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">{new Date(t.created_at).toLocaleDateString()}</td>
                                    <td className="table-td"><StatusBadge label={STATUS_STYLE[t.status].label} variant={STATUS_STYLE[t.status].variant} /></td>
                                    <td className="table-td text-right">
                                        {t.status === 'pending_approval' ? (
                                            <Link href={`/office/stocktakes/${t.id}`} className="text-xs font-semibold text-emerald-600 hover:text-emerald-800">
                                                Review
                                            </Link>
                                        ) : (
                                            <span className="text-xs text-slate-300">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {stock_takes.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="table-td text-center text-slate-400 py-10">No stocktakes yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {stock_takes.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {stock_takes.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveState
                                    className={`text-sm px-3 py-1.5 rounded-lg ${link.active ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span key={i} className="text-sm px-3 py-1.5 text-slate-300" dangerouslySetInnerHTML={{ __html: link.label }} />
                            )
                        )}
                    </div>
                )}
            </div>
        </BackOfficeLayout>
    );
}
