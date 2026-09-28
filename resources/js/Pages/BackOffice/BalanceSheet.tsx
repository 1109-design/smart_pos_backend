import React, { FormEvent, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface Line {
    code: string | null;
    name: string;
    amount: number;
}

interface Section {
    category: string;
    lines: Line[];
    total: number;
}

interface Report {
    sections: Section[];
    total_assets: number;
    total_liabilities: number;
    total_equity: number;
    is_balanced: boolean;
}

interface Props {
    as_of: string;
    report: Report;
}

const fmt = (n: number) => n.toFixed(2);

function StatementSection({ section }: { section: Section }) {
    return (
        <div>
            <p className="px-5 py-2.5 bg-slate-50/60 font-semibold text-slate-700 text-sm">{section.category}</p>
            <table className="w-full text-sm">
                <tbody className="divide-y divide-slate-50">
                    {section.lines.map((line) => (
                        <tr key={line.code ?? line.name}>
                            <td className="px-5 py-2.5 pl-9 text-slate-600">{line.name}</td>
                            <td className="px-5 py-2.5 text-right tabular-nums text-slate-700">{fmt(line.amount)}</td>
                        </tr>
                    ))}
                    {section.lines.length === 0 && (
                        <tr>
                            <td className="px-5 py-2.5 pl-9 text-slate-400 italic" colSpan={2}>No activity</td>
                        </tr>
                    )}
                </tbody>
                <tfoot>
                    <tr className="border-t border-slate-100 font-semibold">
                        <td className="px-5 py-2 text-slate-700">Total {section.category}</td>
                        <td className="px-5 py-2 text-right tabular-nums text-slate-800">{fmt(section.total)}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

export default function BackOfficeBalanceSheet({ as_of, report }: Props) {
    const [asOf, setAsOf] = useState(as_of);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get('/office/reports/balance-sheet', { as_of: asOf }, { preserveState: true });
    };

    const assets = report.sections.find((s) => s.category === 'Assets');
    const liabilities = report.sections.find((s) => s.category === 'Liabilities');
    const equity = report.sections.find((s) => s.category === 'Equity');

    return (
        <BackOfficeLayout>
            <Head title="Balance Sheet" />

            <div className="mb-6 flex items-end justify-between flex-wrap gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Balance Sheet</h1>
                    <p className="text-sm text-slate-500 mt-1">What the business owns, owes, and is worth, as of a date.</p>
                </div>
                <form onSubmit={submit} className="flex items-end gap-2">
                    <div>
                        <label className="block text-xs font-semibold text-slate-500 mb-1">As of</label>
                        <input
                            type="date"
                            value={asOf}
                            onChange={(e) => setAsOf(e.target.value)}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <button type="submit" className="btn-primary py-2 px-4">Run</button>
                </form>
            </div>

            <div
                className={`mb-4 rounded-xl border px-4 py-3 text-sm ${
                    report.is_balanced
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'border-red-200 bg-red-50 text-red-700'
                }`}
            >
                {report.is_balanced
                    ? 'Balanced — Assets equal Liabilities plus Equity.'
                    : `Out of balance by ${fmt(Math.abs(report.total_assets - (report.total_liabilities + report.total_equity)))}. This should never happen — check for a data issue.`}
            </div>

            {equity?.lines.some((l) => l.name === 'Current Earnings (unclosed)') && (
                <p className="mb-4 text-xs text-slate-400 leading-relaxed">
                    "Current Earnings (unclosed)" is the period's net income rolled into Equity for display —
                    there's no year-end close yet, so this line is computed live rather than posted.
                </p>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    {assets && <StatementSection section={assets} />}
                </div>
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden divide-y divide-slate-100">
                    {liabilities && <StatementSection section={liabilities} />}
                    {equity && <StatementSection section={equity} />}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
