import React, { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface ProjectRow {
    id: string;
    name: string;
    reference: string | null;
    status: 'active' | 'closed';
    budget: number | null;
    spent: number;
    created_at: string;
}

interface Props {
    projects: ProjectRow[];
}

const fmt = (n: number) => n.toFixed(2);

export default function BackOfficeProjects({ projects }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const [showNew, setShowNew] = useState(false);
    const form = useForm({
        name: '',
        reference: '',
        budget: '',
        notes: '',
    });

    const closeModal = () => {
        setShowNew(false);
        form.reset();
        form.clearErrors();
    };

    const submitNew = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/projects', {
            preserveScroll: true,
            onSuccess: () => {
                closeModal();
            },
        });
    };

    return (
        <BackOfficeLayout>
            <Head title="Projects" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Projects</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Jobs stock and expenses get tagged against — a project's cost build-up is the stock issued to
                        it plus its own direct expenses, checked against its budget.
                    </p>
                </div>
                <button onClick={() => setShowNew(true)} className="btn-primary py-2">
                    + New Project
                </button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-3">Project</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3 text-right">Spent</th>
                                <th className="px-5 py-3 text-right">Budget</th>
                                <th className="px-5 py-3 text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {projects.map((p) => {
                                const variance = p.budget !== null ? p.budget - p.spent : null;
                                return (
                                    <tr key={p.id} className="hover:bg-slate-50/60">
                                        <td className="px-5 py-3">
                                            <Link href={`/office/projects/${p.id}`} className="font-medium text-slate-900 hover:text-emerald-700 hover:underline">
                                                {p.name}
                                            </Link>
                                            {p.reference && <span className="ml-2 text-xs text-slate-400">{p.reference}</span>}
                                        </td>
                                        <td className="px-5 py-3">
                                            <StatusBadge
                                                label={p.status === 'active' ? 'Active' : 'Closed'}
                                                variant={p.status === 'active' ? 'green' : 'gray'}
                                            />
                                        </td>
                                        <td className="px-5 py-3 text-right tabular-nums text-slate-700">{fmt(p.spent)}</td>
                                        <td className="px-5 py-3 text-right tabular-nums text-slate-700">
                                            {p.budget !== null ? fmt(p.budget) : '—'}
                                        </td>
                                        <td className={`px-5 py-3 text-right tabular-nums font-semibold ${
                                            variance === null ? 'text-slate-300' : variance < 0 ? 'text-red-600' : 'text-emerald-700'
                                        }`}>
                                            {variance !== null ? fmt(variance) : '—'}
                                        </td>
                                    </tr>
                                );
                            })}
                            {projects.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-5 py-10 text-center text-sm text-slate-400">No projects yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal show={showNew} onClose={closeModal} maxWidth="md">
                <div className="p-6">
                    <div className="flex items-start justify-between pb-4 mb-4 border-b border-slate-100">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor" className="w-5 h-5">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z" />
                                </svg>
                            </div>
                            <div>
                                <h2 className="text-lg font-bold text-slate-900">New Project</h2>
                                <p className="text-xs text-slate-500">Track requisitions and expenses against a job budget</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={closeModal}
                            disabled={form.processing}
                            className="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-lg hover:bg-slate-100"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                            </svg>
                        </button>
                    </div>

                    <form onSubmit={submitNew} className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-700 mb-1">
                                Project Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                required
                                autoFocus
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Shopfront Renovation, Site A"
                                className="w-full text-sm rounded-xl border border-slate-200 px-3.5 py-2.5 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            />
                            {form.errors.name && (
                                <p className="text-red-500 text-xs mt-1 font-medium">{form.errors.name}</p>
                            )}
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Reference / Job Code <span className="text-slate-400 font-normal">(optional)</span>
                                </label>
                                <input
                                    type="text"
                                    value={form.data.reference}
                                    onChange={(e) => form.setData('reference', e.target.value)}
                                    placeholder="e.g. JOB-042 or Site Code"
                                    className="w-full text-sm rounded-xl border border-slate-200 px-3.5 py-2.5 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                />
                                {form.errors.reference && (
                                    <p className="text-red-500 text-xs mt-1 font-medium">{form.errors.reference}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 mb-1">
                                    Budget Cap <span className="text-slate-400 font-normal">(optional)</span>
                                </label>
                                <div className="relative">
                                    <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-semibold">$</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={form.data.budget}
                                        onChange={(e) => form.setData('budget', e.target.value)}
                                        placeholder="0.00"
                                        className="w-full text-sm rounded-xl border border-slate-200 pl-8 pr-3.5 py-2.5 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                    />
                                </div>
                                {form.errors.budget && (
                                    <p className="text-red-500 text-xs mt-1 font-medium">{form.errors.budget}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-slate-700 mb-1">
                                Notes / Description <span className="text-slate-400 font-normal">(optional)</span>
                            </label>
                            <textarea
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                rows={3}
                                placeholder="Scope of work, site instructions, client notes…"
                                className="w-full text-sm rounded-xl border border-slate-200 px-3.5 py-2.5 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            />
                            {form.errors.notes && (
                                <p className="text-red-500 text-xs mt-1 font-medium">{form.errors.notes}</p>
                            )}
                        </div>

                        <div className="rounded-xl bg-slate-50 border border-slate-100 p-3 text-xs text-slate-500">
                            Stock issued from requisitions and expenses tagged against this job will accumulate toward the project budget.
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-2">
                            <button
                                type="button"
                                onClick={closeModal}
                                disabled={form.processing}
                                className="text-sm font-medium text-slate-600 px-4 py-2 hover:bg-slate-100 rounded-xl transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing || !form.data.name.trim()}
                                className="btn-primary py-2.5 px-5 disabled:opacity-50 flex items-center gap-2"
                            >
                                {form.processing && (
                                    <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                )}
                                {form.processing ? 'Creating…' : 'Create Project'}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>
        </BackOfficeLayout>
    );
}
