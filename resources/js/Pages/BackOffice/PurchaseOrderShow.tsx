import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type PoStatus = 'draft' | 'sent' | 'partial' | 'received' | 'cancelled';

interface Item {
    id: string;
    product_name: string;
    ordered_qty: string;
    received_qty: string;
    unit_cost: string;
    received_unit_cost: string | null;
}

interface AuditEntry {
    id: number;
    user_name: string | null;
    action: string;
    note: string | null;
    created_at: string;
}

interface Order {
    id: string;
    po_number: string;
    status: PoStatus;
    supplier_name: string | null;
    notes: string | null;
    expected_date: string | null;
    total_ordered: string;
    total_received: string;
    receiving_location: { id: string; name: string } | null;
    items: Item[];
    created_at: string;
}

interface Props {
    order: Order;
    audit: AuditEntry[];
}

const STATUS_STYLE: Record<PoStatus, { label: string; variant: 'amber' | 'blue' | 'violet' | 'green' | 'red' }> = {
    draft: { label: 'Draft', variant: 'amber' },
    sent: { label: 'Sent', variant: 'blue' },
    partial: { label: 'Partially Received', variant: 'violet' },
    received: { label: 'Received', variant: 'green' },
    cancelled: { label: 'Cancelled', variant: 'red' },
};

export default function BackOfficePurchaseOrderShow({ order, audit }: Props) {
    const cancel = () => router.post(`/office/purchase-orders/${order.id}/cancel`, {}, { preserveScroll: true });

    return (
        <BackOfficeLayout>
            <Head title={`PO ${order.po_number}`} />

            <Link href="/office/purchase-orders" className="text-xs font-semibold text-slate-500 hover:text-slate-700">
                ← All purchase orders
            </Link>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-3 mb-6">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{order.po_number}</h1>
                        <StatusBadge label={STATUS_STYLE[order.status].label} variant={STATUS_STYLE[order.status].variant} />
                    </div>
                    <p className="text-sm text-slate-500 mt-1">
                        {order.supplier_name ?? 'No supplier on file'} · receiving at {order.receiving_location?.name ?? '—'}
                    </p>
                </div>
                {['draft', 'sent'].includes(order.status) && (
                    <button onClick={cancel} className="text-sm font-semibold text-red-500 hover:text-red-700 flex-shrink-0">
                        Cancel this order
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-800">Items</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm min-w-[520px]">
                            <thead>
                                <tr className="bg-slate-50">
                                    <th className="table-th">Product</th>
                                    <th className="table-th text-right">Ordered</th>
                                    <th className="table-th text-right">Received</th>
                                    <th className="table-th text-right">Unit cost</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {order.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="table-td text-slate-700">{item.product_name}</td>
                                        <td className="table-td text-right text-slate-600">{Number(item.ordered_qty).toFixed(2)}</td>
                                        <td className="table-td text-right text-slate-600">{Number(item.received_qty).toFixed(2)}</td>
                                        <td className="table-td text-right text-slate-600">
                                            {Number(item.received_unit_cost ?? item.unit_cost).toFixed(2)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="bg-slate-50 font-semibold">
                                    <td className="table-td text-slate-700">Total</td>
                                    <td className="table-td text-right text-slate-700">{Number(order.total_ordered).toFixed(2)}</td>
                                    <td className="table-td text-right text-slate-700">{Number(order.total_received).toFixed(2)}</td>
                                    <td className="table-td" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    {order.notes && (
                        <div className="px-5 py-4 border-t border-slate-100 text-sm text-slate-600">
                            <span className="font-semibold text-slate-500">Notes: </span>{order.notes}
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-800">Activity</p>
                    </div>
                    <div className="divide-y divide-slate-50 max-h-[480px] overflow-y-auto">
                        {audit.map((entry) => (
                            <div key={entry.id} className="px-5 py-3">
                                <p className="text-sm text-slate-700">
                                    <span className="font-semibold">{entry.user_name ?? 'Someone'}</span> {entry.action}
                                </p>
                                {entry.note && <p className="text-xs text-slate-500 mt-0.5">{entry.note}</p>}
                                <p className="text-xs text-slate-400 mt-1">{new Date(entry.created_at).toLocaleString()}</p>
                            </div>
                        ))}
                        {audit.length === 0 && (
                            <p className="px-5 py-8 text-center text-sm text-slate-400">No activity recorded yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
