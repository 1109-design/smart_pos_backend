import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type ApprovalStatus = 'pending' | 'approved' | 'rejected';

interface ApprovalRow {
    id: string;
    subject_type: string;
    subject_id: string;
    action: string;
    reason: string | null;
    payload_json: { reason?: string; po_number?: string; supplier_name?: string } | null;
    status: ApprovalStatus;
    requested_by: { id: string; name: string } | null;
    approver: { id: string; name: string } | null;
    approved_at: string | null;
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    requests: Paginated<ApprovalRow>;
    filters: { status: string };
}

const STATUS_STYLE: Record<ApprovalStatus, { label: string; variant: 'amber' | 'green' | 'red' }> = {
    pending: { label: 'Pending', variant: 'amber' },
    approved: { label: 'Approved', variant: 'green' },
    rejected: { label: 'Rejected', variant: 'red' },
};

const ACTION_LABELS: Record<string, string> = {
    void_transaction: 'Void sale',
    refund_transaction: 'Refund',
    change_exchange_rate: 'Exchange rate change',
    approve_purchase_order: 'Purchase order over threshold',
};

export default function BackOfficeApprovals({ requests, filters }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const decide = (id: string, decision: 'approve' | 'reject') => {
        const reason = window.prompt(decision === 'reject' ? 'Reason for rejecting (optional):' : 'Note (optional):') ?? undefined;
        router.post(`/office/approvals/${id}/${decision}`, { reason }, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Approvals" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Approvals</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Actions raised at a till with no manager on site to approve them there — void/refund requests and
                    exchange-rate changes wait here until an owner or manager reviews them remotely.
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
                    onChange={(e) => router.get('/office/approvals', { status: e.target.value }, { preserveState: true })}
                    className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="all">All</option>
                </select>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Action</th>
                                <th className="table-th">Requested by</th>
                                <th className="table-th">Raised</th>
                                <th className="table-th">Status</th>
                                <th className="table-th">Resolved by</th>
                                <th className="table-th text-right">Decision</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {requests.data.map((r) => (
                                <tr key={r.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">
                                        {ACTION_LABELS[r.action] ?? r.action}
                                        {r.action === 'approve_purchase_order' && r.payload_json?.po_number && (
                                            <div className="text-xs font-normal text-slate-500">
                                                {r.payload_json.po_number} — {r.payload_json.supplier_name}
                                                {r.payload_json.reason && <> ({r.payload_json.reason})</>}
                                            </div>
                                        )}
                                    </td>
                                    <td className="table-td text-slate-600">{r.requested_by?.name ?? '—'}</td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">{new Date(r.created_at).toLocaleString()}</td>
                                    <td className="table-td"><StatusBadge label={STATUS_STYLE[r.status].label} variant={STATUS_STYLE[r.status].variant} /></td>
                                    <td className="table-td text-slate-600">{r.approver?.name ?? '—'}</td>
                                    <td className="table-td text-right space-x-3">
                                        {r.status === 'pending' ? (
                                            <>
                                                <button
                                                    onClick={() => decide(r.id, 'approve')}
                                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    onClick={() => decide(r.id, 'reject')}
                                                    className="text-xs font-semibold text-red-600 hover:text-red-800"
                                                >
                                                    Reject
                                                </button>
                                            </>
                                        ) : (
                                            <span className="text-xs text-slate-300">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {requests.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="table-td text-center text-slate-400 py-10">No requests here.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {requests.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {requests.links.map((link, i) =>
                            link.url ? (
                                <a
                                    key={i}
                                    href={link.url}
                                    onClick={(e) => { e.preventDefault(); router.get(link.url!, {}, { preserveState: true }); }}
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
