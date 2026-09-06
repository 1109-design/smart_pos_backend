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
    total_revenue: number;
    total_cost_of_sales: number;
    total_expenses: number;
    net_income: number;
}

interface Props {
    from: string;
    to: string;
    report: Report;
}

const fmt = (n: number) => n.toFixed(2);

export default function BackOfficeIncomeStatement({ from, to, report }: Props) {
    const [range, setRange] = useState({ from, to });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get('/office/reports/income-statement', range, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Income Statement" />

            <div className="mb-6 flex items-end justify-between flex-wrap gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Income Statement</h1>
                    <p className="text-sm text-slate-500 mt-1">Profit and loss for the selected period.</p>
                </div>
                <form onSubmit={submit} className="flex items-end gap-2">
                    <div>
                        <label className="block text-xs font-semibold text-slate-500 mb-1">From</label>
                        <input
                            type="date"
                            value={range.from}
                            onChange={(e) => setRange({ ...range, from: e.target.value })}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-semibold text-slate-500 mb-1">To</label>
                        <input
                            type="date"
                            value={range.to}
                            onChange={(e) => setRange({ ...range, to: e.target.value })}
                            className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                    </div>
                    <button type="submit" className="btn-primary py-2 px-4">Run</button>
                </form>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <tbody className="divide-y divide-slate-50">
                            {report.sections.map((section) => (
                                <React.Fragment key={section.category}>
                                    <tr className="bg-slate-50/60">
                                        <td className="px-5 py-2.5 font-semibold text-slate-700" colSpan={2}>{section.category}</td>
                                    </tr>
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
                                    <tr className="border-b border-slate-100 font-semibold">
                                        <td className="px-5 py-2 text-slate-700">Total {section.category}</td>
                                        <td className="px-5 py-2 text-right tabular-nums text-slate-800">{fmt(section.total)}</td>
                                    </tr>
                                </React.Fragment>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 border-slate-100">
                                <td className="px-5 py-3 text-slate-500">Revenue</td>
                                <td className="px-5 py-3 text-right tabular-nums text-slate-700">{fmt(report.total_revenue)}</td>
                            </tr>
                            <tr>
                                <td className="px-5 py-3 text-slate-500">Cost of Sales</td>
                                <td className="px-5 py-3 text-right tabular-nums text-slate-700">({fmt(report.total_cost_of_sales)})</td>
                            </tr>
                            <tr>
                                <td className="px-5 py-3 text-slate-500">Expenses</td>
                                <td className="px-5 py-3 text-right tabular-nums text-slate-700">({fmt(report.total_expenses)})</td>
                            </tr>
                            <tr className="border-t-2 border-slate-100 font-bold text-base">
                                <td className="px-5 py-3.5 text-slate-900">Net Income</td>
                                <td
                                    className={`px-5 py-3.5 text-right tabular-nums ${
                                        report.net_income >= 0 ? 'text-emerald-700' : 'text-red-600'
                                    }`}
                                >
                                    {fmt(report.net_income)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
