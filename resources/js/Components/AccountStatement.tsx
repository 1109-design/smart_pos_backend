import React from 'react';

interface StatementLine {
    date: string;
    description: string | null;
    debit: number;
    credit: number;
    running_balance: number;
}

interface Statement {
    opening_balance: number;
    closing_balance: number;
    lines: StatementLine[];
}

interface AgingBuckets {
    current: number;
    days_31_60: number;
    days_61_90: number;
    days_91_120: number;
    over_120: number;
}

interface Aging {
    buckets: AgingBuckets;
    total_outstanding: number;
    credit_balance: number;
}

interface Props {
    statement: Statement;
    aging: Aging;
    /** "owes you" for a debtor, "you owe" for a creditor. */
    balanceLabel: string;
}

const fmt = (n: number) => n.toFixed(2);

const BUCKET_LABELS: Record<keyof AgingBuckets, string> = {
    current: 'Current',
    days_31_60: '31–60 days',
    days_61_90: '61–90 days',
    days_91_120: '91–120 days',
    over_120: '120+ days',
};

export default function AccountStatement({ statement, aging, balanceLabel }: Props) {
    const hasActivity = statement.lines.length > 0;

    return (
        <div className="space-y-6">
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                <div className="flex items-center justify-between mb-4">
                    <p className="text-sm font-semibold text-slate-800">Account balance</p>
                    <div className="text-right">
                        <p className="text-2xl font-bold text-slate-900">{fmt(aging.total_outstanding)}</p>
                        <p className="text-xs text-slate-400">{balanceLabel}</p>
                    </div>
                </div>

                {aging.credit_balance > 0.005 && (
                    <p className="text-xs text-emerald-600 mb-4">
                        Plus a {fmt(aging.credit_balance)} credit balance (overpaid / prepaid), not included above.
                    </p>
                )}

                <div className="grid grid-cols-5 gap-2">
                    {(Object.keys(BUCKET_LABELS) as (keyof AgingBuckets)[]).map((key) => (
                        <div key={key} className="text-center rounded-xl bg-slate-50 py-2.5">
                            <p className="text-sm font-bold text-slate-900">{fmt(aging.buckets[key])}</p>
                            <p className="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mt-0.5">
                                {BUCKET_LABELS[key]}
                            </p>
                        </div>
                    ))}
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <p className="text-sm font-semibold text-slate-800">Account statement</p>
                    <p className="text-xs text-slate-400">Opening balance: {fmt(statement.opening_balance)}</p>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-2.5">Date</th>
                                <th className="px-5 py-2.5">Description</th>
                                <th className="px-5 py-2.5 text-right">Debit</th>
                                <th className="px-5 py-2.5 text-right">Credit</th>
                                <th className="px-5 py-2.5 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {statement.lines.map((line, i) => (
                                <tr key={i}>
                                    <td className="px-5 py-3 text-slate-500 whitespace-nowrap">
                                        {new Date(line.date).toLocaleDateString()}
                                    </td>
                                    <td className="px-5 py-3 text-slate-700">{line.description ?? '—'}</td>
                                    <td className="px-5 py-3 text-right text-slate-700 tabular-nums">
                                        {line.debit > 0.005 ? fmt(line.debit) : ''}
                                    </td>
                                    <td className="px-5 py-3 text-right text-slate-700 tabular-nums">
                                        {line.credit > 0.005 ? fmt(line.credit) : ''}
                                    </td>
                                    <td className="px-5 py-3 text-right font-semibold text-slate-900 tabular-nums">
                                        {fmt(line.running_balance)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {!hasActivity && (
                        <p className="px-5 py-8 text-center text-sm text-slate-400">No account activity yet.</p>
                    )}
                </div>
            </div>
        </div>
    );
}
