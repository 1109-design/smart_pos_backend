import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface CatalogItem {
    id: string;
    name: string;
    sku: string | null;
    barcode: string | null;
}

interface LocationOption {
    id: string;
    name: string;
}

interface ProjectOption {
    id: string;
    name: string;
    status: 'active' | 'closed';
}

interface RequisitionItemRow {
    id: string;
    product_id: string;
    product_name: string;
    quantity_requested: string;
    quantity_issued: string;
}

type RequisitionStatus = 'pending' | 'approved' | 'rejected' | 'issued' | 'cancelled';

interface RequisitionRow {
    id: string;
    requisition_number: string;
    status: RequisitionStatus;
    purpose: 'general' | 'project';
    project_id: string | null;
    notes: string | null;
    location: { id: string; name: string } | null;
    requested_by: { id: string; name: string } | null;
    approved_by: { id: string; name: string } | null;
    issued_by: { id: string; name: string } | null;
    items: RequisitionItemRow[];
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    requisitions: Paginated<RequisitionRow>;
    locations: LocationOption[];
    catalog: CatalogItem[];
    projects: ProjectOption[];
    can_issue: boolean;
}

const STATUS_STYLE: Record<RequisitionStatus, { label: string; variant: 'amber' | 'blue' | 'green' | 'red' | 'gray' }> = {
    pending: { label: 'Pending', variant: 'amber' },
    approved: { label: 'Approved', variant: 'blue' },
    rejected: { label: 'Rejected', variant: 'red' },
    issued: { label: 'Issued', variant: 'green' },
    cancelled: { label: 'Cancelled', variant: 'gray' },
};

interface FormItem {
    product_id: string;
    quantity_requested: string;
}

export default function BackOfficeRequisitions({ requisitions, locations, catalog, projects, can_issue }: Props) {
    const { flash, errors } = usePage().props as unknown as {
        flash: { success: string | null };
        errors: Record<string, string>;
    };

    const [showNew, setShowNew] = useState(false);
    const [locationId, setLocationId] = useState(locations[0]?.id ?? '');
    const [purpose, setPurpose] = useState<'general' | 'project'>('general');
    const [projectId, setProjectId] = useState('');
    const [notes, setNotes] = useState('');
    const [items, setItems] = useState<FormItem[]>([{ product_id: '', quantity_requested: '' }]);

    const [issuing, setIssuing] = useState<RequisitionRow | null>(null);
    const [issueQty, setIssueQty] = useState<Record<string, string>>({});

    const catalogById = Object.fromEntries(catalog.map((c) => [c.id, c]));

    const resetForm = () => {
        setLocationId(locations[0]?.id ?? '');
        setPurpose('general');
        setProjectId('');
        setNotes('');
        setItems([{ product_id: '', quantity_requested: '' }]);
    };

    const submitNew = () => {
        router.post(
            '/office/requisitions',
            {
                location_id: locationId,
                purpose,
                project_id: purpose === 'project' ? projectId : null,
                notes: notes || null,
                items: items
                    .filter((i) => i.product_id && Number(i.quantity_requested) > 0)
                    .map((i) => ({ product_id: i.product_id, quantity_requested: Number(i.quantity_requested) })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowNew(false);
                    resetForm();
                },
            }
        );
    };

    const openIssue = (r: RequisitionRow) => {
        setIssuing(r);
        setIssueQty(Object.fromEntries(r.items.map((i) => [i.id, i.quantity_requested])));
    };

    const submitIssue = () => {
        if (!issuing) return;
        router.post(
            `/office/requisitions/${issuing.id}/issue`,
            {
                items: issuing.items.map((i) => ({ item_id: i.id, quantity_issued: Number(issueQty[i.id] ?? 0) })),
            },
            { preserveScroll: true, onSuccess: () => setIssuing(null) }
        );
    };

    return (
        <BackOfficeLayout>
            <Head title="Requisitions" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Requisitions</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Nothing leaves the warehouse without this trail — request, approve, then issue against the
                        approved request.
                    </p>
                </div>
                <button onClick={() => setShowNew(true)} className="btn-primary py-2">
                    + New Requisition
                </button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}
            {errors?.requisition && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {errors.requisition}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-3">Requisition</th>
                                <th className="px-5 py-3">Location</th>
                                <th className="px-5 py-3">Purpose</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3">Requested by</th>
                                <th className="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {requisitions.data.map((r) => (
                                <tr key={r.id} className="hover:bg-slate-50/60">
                                    <td className="px-5 py-3 font-medium text-slate-900">{r.requisition_number}</td>
                                    <td className="px-5 py-3 text-slate-600">{r.location?.name ?? '—'}</td>
                                    <td className="px-5 py-3 text-slate-600">
                                        {r.purpose === 'project'
                                            ? `Project: ${projects.find((p) => p.id === r.project_id)?.name ?? 'Unknown'}`
                                            : 'General use'}
                                    </td>
                                    <td className="px-5 py-3">
                                        <StatusBadge label={STATUS_STYLE[r.status].label} variant={STATUS_STYLE[r.status].variant} />
                                    </td>
                                    <td className="px-5 py-3 text-slate-600">{r.requested_by?.name ?? '—'}</td>
                                    <td className="px-5 py-3 text-right space-x-3 whitespace-nowrap">
                                        {r.status === 'pending' && (
                                            <>
                                                <button
                                                    onClick={() => router.post(`/office/requisitions/${r.id}/approve`, {}, { preserveScroll: true })}
                                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    onClick={() => router.post(`/office/requisitions/${r.id}/reject`, {}, { preserveScroll: true })}
                                                    className="text-xs font-semibold text-red-600 hover:text-red-800"
                                                >
                                                    Reject
                                                </button>
                                            </>
                                        )}
                                        {r.status === 'approved' && can_issue && (
                                            <button
                                                onClick={() => openIssue(r)}
                                                className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                            >
                                                Issue
                                            </button>
                                        )}
                                        {(r.status === 'pending' || r.status === 'approved') && (
                                            <button
                                                onClick={() => router.post(`/office/requisitions/${r.id}/cancel`, {}, { preserveScroll: true })}
                                                className="text-xs font-semibold text-slate-500 hover:text-slate-700"
                                            >
                                                Cancel
                                            </button>
                                        )}
                                        {!['pending', 'approved'].includes(r.status) && (
                                            <span className="text-xs text-slate-300">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {requisitions.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-5 py-10 text-center text-slate-400">No requisitions yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {requisitions.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {requisitions.links.map((link, i) =>
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

            {/* New Requisition modal */}
            <Modal show={showNew} onClose={() => setShowNew(false)} maxWidth="lg">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-slate-900 mb-4">New Requisition</h2>

                    <div className="grid grid-cols-2 gap-3 mb-3">
                        <select
                            value={locationId}
                            onChange={(e) => setLocationId(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2"
                        >
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>{l.name}</option>
                            ))}
                        </select>
                        <select
                            value={purpose}
                            onChange={(e) => setPurpose(e.target.value as 'general' | 'project')}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2"
                        >
                            <option value="general">General use</option>
                            <option value="project">Project</option>
                        </select>
                    </div>

                    {purpose === 'project' && (
                        <select
                            value={projectId}
                            onChange={(e) => setProjectId(e.target.value)}
                            className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 mb-3"
                        >
                            <option value="">Select project…</option>
                            {projects.filter((p) => p.status === 'active').map((p) => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                    )}

                    <textarea
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="Notes (optional)"
                        rows={2}
                        className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 mb-4"
                    />

                    <div className="space-y-2 mb-4">
                        {items.map((item, idx) => (
                            <div key={idx} className="flex gap-2 items-center">
                                <select
                                    value={item.product_id}
                                    onChange={(e) => {
                                        const next = [...items];
                                        next[idx] = { ...next[idx], product_id: e.target.value };
                                        setItems(next);
                                    }}
                                    className="flex-1 text-sm rounded-xl border border-slate-200 px-3 py-2"
                                >
                                    <option value="">Select product…</option>
                                    {catalog.map((c) => (
                                        <option key={c.id} value={c.id}>{c.name}{c.sku ? ` (${c.sku})` : ''}</option>
                                    ))}
                                </select>
                                <input
                                    type="number"
                                    min="0"
                                    step="any"
                                    value={item.quantity_requested}
                                    onChange={(e) => {
                                        const next = [...items];
                                        next[idx] = { ...next[idx], quantity_requested: e.target.value };
                                        setItems(next);
                                    }}
                                    placeholder="Qty"
                                    className="w-24 text-sm rounded-xl border border-slate-200 px-3 py-2"
                                />
                                <button
                                    onClick={() => setItems(items.filter((_, i) => i !== idx))}
                                    className="text-slate-400 hover:text-red-600 text-sm px-2"
                                >
                                    ✕
                                </button>
                            </div>
                        ))}
                        <button
                            onClick={() => setItems([...items, { product_id: '', quantity_requested: '' }])}
                            className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                        >
                            + Add item
                        </button>
                    </div>

                    <div className="flex justify-end gap-2">
                        <button onClick={() => setShowNew(false)} className="px-4 py-2 text-sm text-slate-600">Cancel</button>
                        <button onClick={submitNew} className="btn-primary py-2">Raise Requisition</button>
                    </div>
                </div>
            </Modal>

            {/* Issue modal */}
            <Modal show={!!issuing} onClose={() => setIssuing(null)} maxWidth="lg">
                {issuing && (
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-slate-900 mb-1">Issue {issuing.requisition_number}</h2>
                        <p className="text-sm text-slate-500 mb-4">Confirm the quantity actually handed over for each item.</p>

                        <div className="space-y-2 mb-4">
                            {issuing.items.map((item) => (
                                <div key={item.id} className="flex items-center justify-between gap-3">
                                    <span className="text-sm text-slate-700">
                                        {catalogById[item.product_id]?.name ?? item.product_name}
                                        <span className="text-slate-400"> (requested {item.quantity_requested})</span>
                                    </span>
                                    <input
                                        type="number"
                                        min="0"
                                        max={item.quantity_requested}
                                        step="any"
                                        value={issueQty[item.id] ?? ''}
                                        onChange={(e) => setIssueQty({ ...issueQty, [item.id]: e.target.value })}
                                        className="w-24 text-sm rounded-xl border border-slate-200 px-3 py-2"
                                    />
                                </div>
                            ))}
                        </div>

                        <div className="flex justify-end gap-2">
                            <button onClick={() => setIssuing(null)} className="px-4 py-2 text-sm text-slate-600">Cancel</button>
                            <button onClick={submitIssue} className="btn-primary py-2">Confirm Issue</button>
                        </div>
                    </div>
                )}
            </Modal>
        </BackOfficeLayout>
    );
}
