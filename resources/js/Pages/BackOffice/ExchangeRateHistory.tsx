import React from 'react';
import { Head, Link } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface HistoryEntry {
    id: string;
    rate: number;
    source: string;
    locked: boolean;
    valid_from: string | null;
    valid_until: string | null;
    is_current: boolean;
    approved_by: string | null;
    requested_by: string | null;
    reason: string | null;
}

interface Props {
    currency: { code: string; name: string; symbol: string };
    base_currency: string;
    history: HistoryEntry[];
}

export default function BackOfficeExchangeRateHistory({ currency, base_currency, history }: Props) {
    return (
        <BackOfficeLayout>
            <Head title={`${currency.code} Rate History`} />

            <Link href="/office/exchange-rates" className="text-xs font-semibold text-slate-500 hover:text-slate-700">
                ← All exchange rates
            </Link>

            <div className="mt-3 mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">
                    {currency.symbol} {currency.name} ({currency.code})
                </h1>
                <p className="text-sm text-slate-500 mt-1">
                    Full audit history — every approved rate change against {base_currency}, oldest to newest reversed. Nothing
                    here is ever edited or deleted; a new rate is always a new row.
                </p>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="divide-y divide-slate-50">
                    {history.map((entry) => (
                        <div key={entry.id} className="px-5 py-4">
                            <div className="flex items-center justify-between gap-3 flex-wrap">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-semibold text-slate-800">
                                        1 {base_currency} = {entry.rate.toFixed(4)} {currency.code}
                                    </span>
                                    {entry.locked && (
                                        <span className="text-xs text-slate-400" title="Locked rate">🔒</span>
                                    )}
                                </div>
                                <StatusBadge
                                    label={entry.is_current ? 'Current' : 'Superseded'}
                                    variant={entry.is_current ? 'green' : 'gray'}
                                />
                            </div>
                            <p className="text-xs text-slate-500 mt-1">
                                Active {entry.valid_from ? new Date(entry.valid_from).toLocaleString() : '—'}
                                {entry.valid_until
                                    ? ` → ${new Date(entry.valid_until).toLocaleString()}`
                                    : ' → now'}
                            </p>
                            {(entry.requested_by || entry.approved_by) && (
                                <p className="text-xs text-slate-500 mt-1">
                                    {entry.requested_by && <>Requested by <span className="font-medium text-slate-600">{entry.requested_by}</span></>}
                                    {entry.requested_by && entry.approved_by && ' · '}
                                    {entry.approved_by && <>Approved by <span className="font-medium text-slate-600">{entry.approved_by}</span></>}
                                </p>
                            )}
                            {entry.reason && (
                                <p className="text-xs text-slate-500 mt-1 italic">Reason: {entry.reason}</p>
                            )}
                            <p className="text-[11px] text-slate-400 mt-1">Source: {entry.source}</p>
                        </div>
                    ))}
                    {history.length === 0 && (
                        <p className="px-5 py-8 text-center text-sm text-slate-400">No rate changes recorded yet.</p>
                    )}
                </div>
            </div>
        </BackOfficeLayout>
    );
}
