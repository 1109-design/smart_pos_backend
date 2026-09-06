import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
    const [name, setName] = useState('');
    const [reference, setReference] = useState('');
    const [budget, setBudget] = useState('');
    const [notes, setNotes] = useState('');

    const resetForm = () => {
        setName('');
        setReference('');
        setBudget('');
        setNotes('');
    };

    const submitNew = () => {
        router.post(
            '/office/projects',
            { name, reference: reference || null, budget: budget || null, notes: notes || null },
            { preserveScroll: true, onSuccess: () => { setShowNew(false); resetForm(); } }
        );
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

            <Modal show={showNew} onClose={() => setShowNew(false)} maxWidth="md">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-slate-900 mb-4">New Project</h2>

                    <div className="space-y-3">
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Name</label>
                            <input
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Reference (optional)</label>
                            <input
                                value={reference}
                                onChange={(e) => setReference(e.target.value)}
                                placeholder="Site or job code"
                                className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Budget (optional)</label>
                            <input
                                type="number" step="0.01" min="0"
                                value={budget}
                                onChange={(e) => setBudget(e.target.value)}
                                className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Notes (optional)</label>
                            <textarea
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                rows={2}
                                className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2"
                            />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 mt-6">
                        <button onClick={() => setShowNew(false)} className="text-sm text-slate-500 px-4 py-2">Cancel</button>
                        <button onClick={submitNew} disabled={!name} className="btn-primary py-2 px-5 disabled:opacity-50">
                            Create
                        </button>
                    </div>
                </div>
            </Modal>
        </BackOfficeLayout>
    );
}
