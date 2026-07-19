import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';

interface PaymentRow {
    method: string;
    amount: string;
    currency_code: string;
}

interface TransactionRow {
    id: string;
    sale_number: string | null;
    status: string;
    total: string;
    tax_total: string;
    base_currency: string;
    fiscal_status: string | null;
    fiscal_receipt_number: string | null;
    fiscal_qr_code: string | null;
    fiscal_qr_data_uri: string | null;
    created_at: string;
    cashier_name: string | null;
    payments: PaymentRow[];
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface FiscalSummary {
    total: number;
    fiscalised: number;
    pending: number;
    failed: number;
}

interface Props {
    transactions: Paginated<TransactionRow>;
    fiscal_summary: FiscalSummary;
    currency: string;
    filters: { from: string; to: string; fiscal: string };
}

function fmt(amount: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(amount);
}

function methodLabel(method: string): string {
    const map: Record<string, string> = { cash: 'Cash', card: 'Card', mobile: 'Mobile Money', credit: 'Credit' };
    return map[method] ?? method;
}

function FiscalBadge({ status }: { status: string | null }) {
    if (status === 'fiscalised') {
        return <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Fiscalised</span>;
    }
    if (status === 'pending') {
        return <span className="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">Pending</span>;
    }
    if (status === 'failed' || status === 'not_configured') {
        return <span className="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-600">{status === 'failed' ? 'Failed' : 'Not configured'}</span>;
    }
    return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">—</span>;
}

const FISCAL_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'fiscalised', label: 'Fiscalised' },
    { value: 'pending', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
    { value: 'none', label: 'Not fiscal' },
];

export default function BackOfficeTransactions({ transactions, fiscal_summary, currency, filters }: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [qrTransaction, setQrTransaction] = useState<TransactionRow | null>(null);

    const applyFilter = (fiscal?: string) => {
        router.get('/office/transactions', { from, to, fiscal: fiscal ?? filters.fiscal }, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Transactions" />

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Transactions</h1>
                    <p className="text-sm text-slate-500 mt-1">{transactions.total} transactions in range</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <input
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 flex-1 min-w-0"
                    />
                    <span className="text-slate-400 text-sm flex-shrink-0">to</span>
                    <input
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 flex-1 min-w-0"
                    />
                    <button onClick={() => applyFilter()} className="btn-primary py-2 flex-shrink-0">Apply</button>
                </div>
            </div>

            {/* Fiscal summary cards */}
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
                {[
                    { label: 'Total', value: fiscal_summary.total, tone: 'text-slate-900' },
                    { label: 'Fiscalised', value: fiscal_summary.fiscalised, tone: 'text-emerald-600' },
                    { label: 'Pending ZIMRA', value: fiscal_summary.pending, tone: 'text-amber-600' },
                    { label: 'Failed / Not configured', value: fiscal_summary.failed, tone: 'text-red-600' },
                ].map(({ label, value, tone }) => (
                    <div key={label} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                        <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{label}</p>
                        <p className={`text-xl font-bold mt-1.5 ${tone}`}>{Number(value ?? 0)}</p>
                    </div>
                ))}
            </div>

            {/* Fiscal status filter chips */}
            <div className="flex flex-wrap gap-2 mb-4">
                {FISCAL_FILTERS.map(({ value, label }) => (
                    <button
                        key={value}
                        onClick={() => applyFilter(value)}
                        className={`text-sm px-3 py-1.5 rounded-full border transition ${
                            filters.fiscal === value
                                ? 'bg-emerald-600 border-emerald-600 text-white'
                                : 'bg-white border-slate-200 text-slate-600 hover:border-emerald-300'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[760px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Sale #</th>
                                <th className="table-th">Date</th>
                                <th className="table-th">Cashier</th>
                                <th className="table-th">Payment</th>
                                <th className="table-th text-right">Total</th>
                                <th className="table-th">Status</th>
                                <th className="table-th">ZIMRA</th>
                                <th className="table-th text-right">QR</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {transactions.data.map((tx) => (
                                <tr key={tx.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">
                                        {tx.sale_number ?? tx.id.slice(0, 8).toUpperCase()}
                                    </td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">
                                        {new Date(tx.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                    </td>
                                    <td className="table-td text-slate-600">{tx.cashier_name ?? '—'}</td>
                                    <td className="table-td text-slate-600">
                                        {tx.payments.length === 0
                                            ? '—'
                                            : [...new Set(tx.payments.map((p) => methodLabel(p.method)))].join(' + ')}
                                    </td>
                                    <td className="table-td text-right font-semibold text-slate-900">{fmt(Number(tx.total), currency)}</td>
                                    <td className="table-td">
                                        <span className={`text-xs font-medium ${tx.status === 'completed' ? 'text-emerald-600' : tx.status === 'voided' ? 'text-red-500' : 'text-slate-500'}`}>
                                            {tx.status}
                                        </span>
                                    </td>
                                    <td className="table-td"><FiscalBadge status={tx.fiscal_status} /></td>
                                    <td className="table-td text-right">
                                        {tx.fiscal_qr_data_uri ? (
                                            <button
                                                onClick={() => setQrTransaction(tx)}
                                                className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                            >
                                                View QR
                                            </button>
                                        ) : (
                                            <span className="text-xs text-slate-300">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {transactions.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-6 py-10 text-center text-sm text-slate-400">
                                        No transactions match this range and filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {transactions.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {transactions.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveState
                                    className={`text-sm px-3 py-1.5 rounded-lg ${
                                        link.active ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <span
                                    key={i}
                                    className="text-sm px-3 py-1.5 text-slate-300"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )
                        )}
                    </div>
                )}
            </div>

            {/* QR modal */}
            <Modal show={qrTransaction !== null} onClose={() => setQrTransaction(null)} maxWidth="sm">
                {qrTransaction && (
                    <div className="p-6 text-center">
                        <p className="text-sm font-semibold text-slate-700 mb-1">
                            ZIMRA Verification — {qrTransaction.sale_number ?? qrTransaction.id.slice(0, 8).toUpperCase()}
                        </p>
                        {qrTransaction.fiscal_receipt_number && (
                            <p className="text-xs text-slate-500 mb-4">Fiscal receipt no. {qrTransaction.fiscal_receipt_number}</p>
                        )}
                        <img
                            src={qrTransaction.fiscal_qr_data_uri!}
                            alt="ZIMRA verification QR code"
                            className="mx-auto rounded-lg border border-slate-100"
                            width={220}
                            height={220}
                        />
                        <a
                            href={qrTransaction.fiscal_qr_code!}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-4 inline-block text-xs text-emerald-600 hover:text-emerald-800 break-all"
                        >
                            {qrTransaction.fiscal_qr_code}
                        </a>
                    </div>
                )}
            </Modal>
        </BackOfficeLayout>
    );
}
