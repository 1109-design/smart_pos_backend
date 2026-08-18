import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type PoStatus = 'draft' | 'sent' | 'partial' | 'received' | 'cancelled';

interface PurchaseOrderRow {
    id: string;
    po_number: string;
    status: PoStatus;
    supplier_name: string | null;
    total_ordered: string;
    total_received: string;
    expected_date: string | null;
    receiving_location: { id: string; name: string } | null;
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    orders: Paginated<PurchaseOrderRow>;
    suppliers: { id: string; name: string }[];
    filters: { status: string; supplier: string };
}

const STATUS_STYLE: Record<PoStatus, { label: string; variant: 'amber' | 'blue' | 'violet' | 'green' | 'red' }> = {
    draft: { label: 'Draft', variant: 'amber' },
    sent: { label: 'Sent', variant: 'blue' },
    partial: { label: 'Partially Received', variant: 'violet' },
    received: { label: 'Received', variant: 'green' },
    cancelled: { label: 'Cancelled', variant: 'red' },
};

export default function BackOfficePurchaseOrders({ orders, suppliers, filters }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const applyFilter = (next: Partial<typeof filters>) => {
        router.get('/office/purchase-orders', { ...filters, ...next }, { preserveState: true });
    };

    const cancel = (order: PurchaseOrderRow) => {
        router.post(`/office/purchase-orders/${order.id}/cancel`, {}, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Purchase Orders" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Purchase Orders</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Orders are created and received at the till, where stock is physically handled — from here you can review
                    every order and cancel a draft or sent one before it goes any further.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="flex flex-wrap gap-3 mb-4">
                <select
                    value={filters.status}
                    onChange={(e) => applyFilter({ status: e.target.value })}
                    className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="all">All statuses</option>
                    {Object.entries(STATUS_STYLE).map(([value, s]) => (
                        <option key={value} value={value}>{s.label}</option>
                    ))}
                </select>
                <select
                    value={filters.supplier}
                    onChange={(e) => applyFilter({ supplier: e.target.value })}
                    className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="all">All suppliers</option>
                    {suppliers.map((s) => (
                        <option key={s.id} value={s.id}>{s.name}</option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">PO #</th>
                                <th className="table-th">Supplier</th>
                                <th className="table-th">Receiving at</th>
                                <th className="table-th">Ordered</th>
                                <th className="table-th">Received</th>
                                <th className="table-th">Status</th>
                                <th className="table-th text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {orders.data.map((o) => (
                                <tr key={o.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">
                                        <Link href={`/office/purchase-orders/${o.id}`} className="hover:text-emerald-700 hover:underline">
                                            {o.po_number}
                                        </Link>
                                    </td>
                                    <td className="table-td text-slate-600">{o.supplier_name ?? '—'}</td>
                                    <td className="table-td text-slate-600">{o.receiving_location?.name ?? '—'}</td>
                                    <td className="table-td text-slate-600">{Number(o.total_ordered).toFixed(2)}</td>
                                    <td className="table-td text-slate-600">{Number(o.total_received).toFixed(2)}</td>
                                    <td className="table-td"><StatusBadge label={STATUS_STYLE[o.status].label} variant={STATUS_STYLE[o.status].variant} /></td>
                                    <td className="table-td text-right">
                                        {['draft', 'sent'].includes(o.status) ? (
                                            <button onClick={() => cancel(o)} className="text-xs font-semibold text-red-400 hover:text-red-600">
                                                Cancel
                                            </button>
                                        ) : (
                                            <span className="text-xs text-slate-300">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {orders.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="table-td text-center text-slate-400 py-10">No purchase orders yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {orders.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {orders.links.map((link, i) =>
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
