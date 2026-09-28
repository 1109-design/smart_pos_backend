import React, { FormEvent, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface AccountOption {
    id: string;
    code: string;
    name: string;
    category: string | null;
}

interface JournalLineRow {
    id: string;
    debit: number;
    credit: number;
    account: { code: string; name: string } | null;
}

interface JournalRow {
    id: string;
    journal_number: string;
    trans_date: string;
    description: string | null;
    status: 'draft' | 'posted' | 'reversed';
    source_type: string;
    lines: JournalLineRow[];
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    journals: Paginated<JournalRow>;
    accounts: AccountOption[];
}

const fmt = (n: number) => n.toFixed(2);
const today = () => new Date().toISOString().slice(0, 10);

interface DraftLine {
    key: number;
    gl_account_id: string;
    debit: string;
    credit: string;
}

function newLine(key: number): DraftLine {
    return { key, gl_account_id: '', debit: '', credit: '' };
}

export default function BackOfficeJournalEntries({ journals, accounts }: Props) {
    const { flash, errors } = usePage().props as unknown as { flash: { success: string | null }; errors: Record<string, string> };
    const [transDate, setTransDate] = useState(today());
    const [description, setDescription] = useState('');
    const [lines, setLines] = useState<DraftLine[]>([newLine(0), newLine(1)]);
    const [nextKey, setNextKey] = useState(2);
    const [processing, setProcessing] = useState(false);

    const totals = useMemo(() => {
        const debit = lines.reduce((sum, l) => sum + (parseFloat(l.debit) || 0), 0);
        const credit = lines.reduce((sum, l) => sum + (parseFloat(l.credit) || 0), 0);
        return { debit, credit, balanced: Math.abs(debit - credit) < 0.005 && debit > 0 };
    }, [lines]);

    const updateLine = (key: number, patch: Partial<DraftLine>) => {
        setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
    };

    const addLine = () => {
        setLines((prev) => [...prev, newLine(nextKey)]);
        setNextKey((k) => k + 1);
    };

    const removeLine = (key: number) => {
        setLines((prev) => (prev.length > 2 ? prev.filter((l) => l.key !== key) : prev));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            '/office/journal-entries',
            {
                trans_date: transDate,
                description,
                lines: lines
                    .filter((l) => l.gl_account_id && (l.debit || l.credit))
                    .map((l) => ({ gl_account_id: l.gl_account_id, debit: l.debit || 0, credit: l.credit || 0 })),
            },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setDescription('');
                    setLines([newLine(nextKey), newLine(nextKey + 1)]);
                    setNextKey((k) => k + 2);
                },
            }
        );
    };

    const reverse = (row: JournalRow) => {
        const reason = window.prompt(`Reason for reversing ${row.journal_number} (optional):`) ?? undefined;
        router.post(`/office/journal-entries/${row.id}/reverse`, { reason }, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Journal Entries" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Journal Entries</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Manual postings for adjustments nothing else creates automatically — opening balances, corrections,
                    accruals. Posts immediately; correcting one afterward means reversing it, not editing it.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}
            {errors?.journal && (
                <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {errors.journal}
                </div>
            )}

            <form onSubmit={submit} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 mb-8 space-y-4">
                <div className="flex flex-wrap gap-4">
                    <div>
                        <label className="block text-xs font-semibold text-slate-500 mb-1">Date</label>
                        <input
                            type="date" required
                            value={transDate}
                            onChange={(e) => setTransDate(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div className="flex-1 min-w-[240px]">
                        <label className="block text-xs font-semibold text-slate-500 mb-1">Description</label>
                        <input
                            type="text" required
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="What is this entry for?"
                            className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                </div>

                <div className="space-y-2">
                    {lines.map((line) => (
                        <div key={line.key} className="flex flex-wrap items-center gap-2">
                            <select
                                value={line.gl_account_id}
                                onChange={(e) => updateLine(line.key, { gl_account_id: e.target.value })}
                                className="flex-1 min-w-[220px] text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            >
                                <option value="">Select account…</option>
                                {accounts.map((a) => (
                                    <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
                                ))}
                            </select>
                            <input
                                type="number" step="0.01" min="0" placeholder="Debit"
                                value={line.debit}
                                onChange={(e) => updateLine(line.key, { debit: e.target.value, credit: e.target.value ? '' : line.credit })}
                                className="w-28 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            <input
                                type="number" step="0.01" min="0" placeholder="Credit"
                                value={line.credit}
                                onChange={(e) => updateLine(line.key, { credit: e.target.value, debit: e.target.value ? '' : line.debit })}
                                className="w-28 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                            />
                            <button
                                type="button"
                                onClick={() => removeLine(line.key)}
                                disabled={lines.length <= 2}
                                className="text-xs text-slate-400 hover:text-red-600 disabled:opacity-30 px-2"
                            >
                                Remove
                            </button>
                        </div>
                    ))}
                </div>

                <div className="flex items-center justify-between flex-wrap gap-3 pt-2 border-t border-slate-100">
                    <button type="button" onClick={addLine} className="text-xs font-semibold text-emerald-700 hover:text-emerald-800">
                        + Add line
                    </button>
                    <div className="flex items-center gap-4 text-sm">
                        <span className="text-slate-500">Debit <span className="tabular-nums font-semibold text-slate-800">{fmt(totals.debit)}</span></span>
                        <span className="text-slate-500">Credit <span className="tabular-nums font-semibold text-slate-800">{fmt(totals.credit)}</span></span>
                        {!totals.balanced && (
                            <span className="text-xs text-amber-600 font-semibold">Not balanced</span>
                        )}
                    </div>
                    <button type="submit" disabled={processing || !totals.balanced} className="btn-primary py-2 px-5 disabled:opacity-50">
                        {processing ? 'Posting…' : 'Post Entry'}
                    </button>
                </div>
            </form>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Journal #</th>
                                <th className="table-th">Date</th>
                                <th className="table-th">Description</th>
                                <th className="table-th">Lines</th>
                                <th className="table-th">Status</th>
                                <th className="table-th text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {journals.data.map((j) => (
                                <tr key={j.id} className="hover:bg-slate-50/60 align-top">
                                    <td className="table-td font-medium text-slate-900 whitespace-nowrap">{j.journal_number}</td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">{new Date(j.trans_date).toLocaleDateString()}</td>
                                    <td className="table-td text-slate-600">{j.description ?? '—'}</td>
                                    <td className="table-td text-slate-600">
                                        {j.lines.map((l) => (
                                            <div key={l.id} className="whitespace-nowrap">
                                                {l.account?.code} {l.account?.name}{' '}
                                                <span className="tabular-nums text-slate-400">
                                                    {l.debit > 0.005 ? `Dr ${fmt(l.debit)}` : `Cr ${fmt(l.credit)}`}
                                                </span>
                                            </div>
                                        ))}
                                    </td>
                                    <td className="table-td">
                                        <StatusBadge
                                            label={j.status === 'posted' ? 'Posted' : j.status === 'reversed' ? 'Reversed' : 'Draft'}
                                            variant={j.status === 'posted' ? 'green' : j.status === 'reversed' ? 'red' : 'amber'}
                                        />
                                    </td>
                                    <td className="table-td text-right">
                                        {j.status === 'posted' && j.source_type === 'manual' && (
                                            <button onClick={() => reverse(j)} className="text-xs font-semibold text-red-600 hover:text-red-800">
                                                Reverse
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {journals.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="table-td text-center text-slate-400 py-10">No manual journal entries yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {journals.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {journals.links.map((link, i) =>
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
