import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface CostLine {
    source: 'requisition' | 'expense';
    reference: string | null;
    description: string | null;
    quantity: number | null;
    amount: number;
    date: string | null;
}

interface ProjectDetail {
    id: string;
    name: string;
    reference: string | null;
    notes: string | null;
    status: 'active' | 'closed';
    budget: number | null;
}

interface MilestoneTask {
    id: string;
    title: string;
    is_done: boolean;
}

interface Milestone {
    id: string;
    title: string;
    target_date: string | null;
    percent_complete: number;
    is_overdue: boolean;
    tasks: MilestoneTask[];
}

interface Props {
    project: ProjectDetail;
    lines: CostLine[];
    total_cost: number;
    milestones: Milestone[];
    overall_progress: number;
}

const fmt = (n: number) => n.toFixed(2);

export default function BackOfficeProjectShow({ project, lines, total_cost, milestones, overall_progress }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const variance = project.budget !== null ? project.budget - total_cost : null;

    const toggleStatus = () => {
        router.post(`/office/projects/${project.id}/${project.status === 'active' ? 'close' : 'reopen'}`, {}, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title={project.name} />

            <div className="mb-6 flex items-start justify-between flex-wrap gap-4">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{project.name}</h1>
                        <StatusBadge label={project.status === 'active' ? 'Active' : 'Closed'} variant={project.status === 'active' ? 'green' : 'gray'} />
                    </div>
                    {project.reference && <p className="text-sm text-slate-500 mt-1">{project.reference}</p>}
                    {project.notes && <p className="text-sm text-slate-500 mt-1 max-w-xl">{project.notes}</p>}
                </div>
                <button onClick={toggleStatus} className="text-sm font-semibold text-slate-600 hover:text-slate-900 border border-slate-200 rounded-xl px-4 py-2">
                    {project.status === 'active' ? 'Close Project' : 'Reopen Project'}
                </button>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Cost</p>
                    <p className="text-xl font-bold text-slate-900 mt-1.5">{fmt(total_cost)}</p>
                </div>
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Budget</p>
                    <p className="text-xl font-bold text-slate-900 mt-1.5">{project.budget !== null ? fmt(project.budget) : 'No budget set'}</p>
                </div>
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Variance</p>
                    <p className={`text-xl font-bold mt-1.5 ${variance === null ? 'text-slate-300' : variance < 0 ? 'text-red-600' : 'text-emerald-700'}`}>
                        {variance !== null ? fmt(variance) : '—'}
                    </p>
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden mb-8">
                <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <p className="text-sm font-semibold text-slate-800">Milestones</p>
                        <p className="text-xs text-slate-400 mt-0.5">
                            Managed from the till — read-only here.
                        </p>
                    </div>
                    <p className="text-sm font-semibold text-emerald-600">{overall_progress}% complete</p>
                </div>
                <div className="px-5 pt-4">
                    <div className="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div
                            className="h-full rounded-full bg-emerald-500"
                            style={{ width: `${overall_progress}%` }}
                        />
                    </div>
                </div>
                <div className="p-5">
                    {milestones.length === 0 ? (
                        <p className="text-sm text-slate-400 text-center py-6">No milestones set for this project yet.</p>
                    ) : (
                        <div className="space-y-3">
                            {milestones.map((m) => (
                                <div key={m.id} className="rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                                    <div className="flex items-center justify-between gap-3 flex-wrap">
                                        <p className="text-sm font-semibold text-slate-800">{m.title}</p>
                                        <div className="flex items-center gap-2">
                                            {m.is_overdue && (
                                                <span className="text-[11px] font-semibold text-red-600 bg-red-50 border border-red-200 rounded-md px-1.5 py-0.5">
                                                    Overdue
                                                </span>
                                            )}
                                            {m.target_date && (
                                                <span className={`text-xs ${m.is_overdue ? 'text-red-600 font-semibold' : 'text-slate-400'}`}>
                                                    Due {new Date(m.target_date).toLocaleDateString()}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2 mt-2">
                                        <div className="h-1.5 flex-1 rounded-full bg-slate-200 overflow-hidden">
                                            <div
                                                className={`h-full rounded-full ${m.percent_complete === 100 ? 'bg-emerald-500' : 'bg-violet-500'}`}
                                                style={{ width: `${m.percent_complete}%` }}
                                            />
                                        </div>
                                        <span className="text-xs text-slate-500 flex-shrink-0">
                                            {m.tasks.filter((t) => t.is_done).length}/{m.tasks.length} · {m.percent_complete}%
                                        </span>
                                    </div>
                                    {m.tasks.length > 0 && (
                                        <ul className="mt-3 space-y-1">
                                            {m.tasks.map((t) => (
                                                <li key={t.id} className="flex items-center gap-2 text-xs">
                                                    <span
                                                        className={`w-3.5 h-3.5 rounded border flex-shrink-0 ${
                                                            t.is_done ? 'bg-emerald-500 border-emerald-500' : 'border-slate-300'
                                                        }`}
                                                    />
                                                    <span className={t.is_done ? 'text-slate-400 line-through' : 'text-slate-600'}>
                                                        {t.title}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="px-5 py-4 border-b border-slate-100">
                    <p className="text-sm font-semibold text-slate-800">Cost Build-Up</p>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-2.5">Date</th>
                                <th className="px-5 py-2.5">Source</th>
                                <th className="px-5 py-2.5">Description</th>
                                <th className="px-5 py-2.5 text-right">Qty</th>
                                <th className="px-5 py-2.5 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {lines.map((line, i) => (
                                <tr key={i}>
                                    <td className="px-5 py-3 text-slate-500 whitespace-nowrap">
                                        {line.date ? new Date(line.date).toLocaleDateString() : '—'}
                                    </td>
                                    <td className="px-5 py-3 text-slate-500">
                                        {line.source === 'requisition' ? `Requisition ${line.reference}` : `Expense — ${line.reference}`}
                                    </td>
                                    <td className="px-5 py-3 text-slate-700">{line.description ?? '—'}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-600">
                                        {line.quantity !== null ? line.quantity : ''}
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums font-medium text-slate-900">{fmt(line.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                        {lines.length > 0 && (
                            <tfoot>
                                <tr className="border-t-2 border-slate-100 font-semibold">
                                    <td className="px-5 py-3" colSpan={4}>Total</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-900">{fmt(total_cost)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                    {lines.length === 0 && (
                        <p className="px-5 py-10 text-center text-sm text-slate-400">
                            No stock issued and no expenses recorded against this project yet.
                        </p>
                    )}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
