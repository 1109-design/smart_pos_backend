import React, { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type PoStatus = 'draft' | 'pending_approval' | 'sent' | 'partial' | 'received' | 'cancelled';

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

interface GrvItemRow {
    product_name: string;
    quantity_received: number;
    quantity_accepted: number;
    quantity_rejected: number;
    unit_cost: number;
    landed_unit_cost: number | null;
}

interface GrvInvoice {
    invoice_number: string;
    invoice_date: string;
    amount: number;
}

interface Grv {
    id: string;
    grv_number: string;
    received_date: string;
    posted_to_ledger: boolean;
    value: number;
    invoice: GrvInvoice | null;
    items: GrvItemRow[];
}

interface Props {
    order: Order;
    audit: AuditEntry[];
    grvs: Grv[];
}

const STATUS_STYLE: Record<PoStatus, { label: string; variant: 'amber' | 'blue' | 'violet' | 'green' | 'red' }> = {
    draft: { label: 'Draft', variant: 'amber' },
    pending_approval: { label: 'Pending Approval', variant: 'amber' },
    sent: { label: 'Sent', variant: 'blue' },
    partial: { label: 'Partially Received', variant: 'violet' },
    received: { label: 'Received', variant: 'green' },
    cancelled: { label: 'Cancelled', variant: 'red' },
};

export default function BackOfficePurchaseOrderShow({ order, audit, grvs }: Props) {
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

                <div className="lg:col-span-2 bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-800">Goods received</p>
                        <p className="text-xs text-slate-400 mt-0.5">
                            Created automatically when the till syncs a receipt against this order — nothing to fill in here.
                        </p>
                    </div>
                    <div className="divide-y divide-slate-50">
                        {grvs.map((grv) => (
                            <div key={grv.id} className="px-5 py-4">
                                <div className="flex items-center justify-between gap-3 mb-2">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-semibold text-slate-800">{grv.grv_number}</span>
                                        <span className="text-xs text-slate-400">
                                            {new Date(grv.received_date).toLocaleDateString()}
                                        </span>
                                    </div>
                                    <StatusBadge
                                        label={grv.posted_to_ledger ? 'Posted to ledger' : 'Not posted'}
                                        variant={grv.posted_to_ledger ? 'green' : 'amber'}
                                    />
                                </div>
                                <table className="w-full text-xs">
                                    <tbody className="divide-y divide-slate-50">
                                        {grv.items.map((item, i) => (
                                            <tr key={i}>
                                                <td className="py-1.5 text-slate-600">{item.product_name}</td>
                                                <td className="py-1.5 text-right text-slate-500">
                                                    {item.quantity_accepted.toFixed(2)} accepted
                                                    {item.quantity_rejected > 0 && (
                                                        <span className="text-red-500"> · {item.quantity_rejected.toFixed(2)} rejected</span>
                                                    )}
                                                </td>
                                                <td className="py-1.5 text-right text-slate-500 w-24">
                                                    @ {item.unit_cost.toFixed(2)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {grv.posted_to_ledger && <GrvInvoiceSection grv={grv} />}
                            </div>
                        ))}
                        {grvs.length === 0 && (
                            <p className="px-5 py-8 text-center text-sm text-slate-400">Nothing received against this order yet.</p>
                        )}
                    </div>
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

/**
 * Purchasing & Cash Vault Blueprint, part B. Recording an invoice here
 * clears this GRV's GRN Suspense into a real Accounts Payable liability —
 * the moment it posts, it shows up on the supplier's statement and the
 * Creditor Age Analysis page immediately.
 */
function GrvInvoiceSection({ grv }: { grv: Grv }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        invoice_number: '',
        invoice_date: new Date().toISOString().slice(0, 10),
        amount: grv.value.toFixed(2),
    });

    if (grv.invoice) {
        return (
            <div className="mt-2 pt-2 border-t border-slate-50 flex items-center justify-between text-xs">
                <span className="text-slate-500">
                    Invoice <span className="font-semibold text-slate-700">{grv.invoice.invoice_number}</span>{' '}
                    ({new Date(grv.invoice.invoice_date).toLocaleDateString()})
                </span>
                <span className="font-semibold text-slate-700">{grv.invoice.amount.toFixed(2)}</span>
            </div>
        );
    }

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/office/grvs/${grv.id}/invoice`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    if (!editing) {
        return (
            <div className="mt-2 pt-2 border-t border-slate-50 flex items-center justify-between text-xs">
                <span className="text-amber-600">No supplier invoice recorded yet — GRN Suspense holds {grv.value.toFixed(2)}.</span>
                <button onClick={() => setEditing(true)} className="font-semibold text-emerald-600 hover:text-emerald-800">
                    Record invoice
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="mt-2 pt-2 border-t border-slate-50 flex flex-wrap items-end gap-2">
            <div>
                <label className="text-[10px] font-semibold text-slate-400">Invoice #</label>
                <input
                    type="text"
                    required
                    value={form.data.invoice_number}
                    onChange={(e) => form.setData('invoice_number', e.target.value)}
                    className="block mt-0.5 text-xs rounded-lg border border-slate-200 px-2 py-1 w-28"
                />
            </div>
            <div>
                <label className="text-[10px] font-semibold text-slate-400">Date</label>
                <input
                    type="date"
                    required
                    value={form.data.invoice_date}
                    onChange={(e) => form.setData('invoice_date', e.target.value)}
                    className="block mt-0.5 text-xs rounded-lg border border-slate-200 px-2 py-1"
                />
            </div>
            <div>
                <label className="text-[10px] font-semibold text-slate-400">Amount</label>
                <input
                    type="number" step="0.01" min="0.01" required
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    className="block mt-0.5 text-xs rounded-lg border border-slate-200 px-2 py-1 w-24"
                />
            </div>
            <button type="submit" disabled={form.processing} className="text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg px-3 py-1.5 disabled:opacity-50">
                Save
            </button>
            <button type="button" onClick={() => setEditing(false)} className="text-xs font-semibold text-slate-400 hover:text-slate-600">
                Cancel
            </button>
            {form.errors.amount && <p className="w-full text-xs text-red-500">{form.errors.amount}</p>}
        </form>
    );
}
