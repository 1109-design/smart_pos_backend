import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';
import AccountStatement from '@/Components/AccountStatement';

interface Supplier {
    id: string;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    website: string | null;
    tax_number: string | null;
    is_active: boolean;
}

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

interface Aging {
    buckets: { current: number; days_31_60: number; days_61_90: number; days_91_120: number; over_120: number };
    total_outstanding: number;
    credit_balance: number;
}

interface Props {
    supplier: Supplier;
    statement: Statement;
    aging: Aging;
}

export default function BackOfficeSupplierShow({ supplier, statement, aging }: Props) {
    return (
        <BackOfficeLayout>
            <Head title={supplier.name} />

            <Link href="/office/suppliers" className="text-xs font-semibold text-slate-500 hover:text-slate-700">
                ← All suppliers
            </Link>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-3 mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{supplier.name}</h1>
                {!supplier.is_active && <StatusBadge label="Inactive" variant="gray" />}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-1 space-y-6">
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 space-y-3 text-sm">
                        <div>
                            <p className="text-xs font-semibold text-slate-400">Contact</p>
                            <p className="text-slate-700">{supplier.contact_name ?? '—'}</p>
                            <p className="text-slate-700">{supplier.phone ?? '—'}</p>
                            <p className="text-slate-700">{supplier.email ?? '—'}</p>
                            <p className="text-slate-700">{supplier.address ?? '—'}</p>
                        </div>
                        {supplier.website && (
                            <div>
                                <p className="text-xs font-semibold text-slate-400">Website</p>
                                <p className="text-slate-700">{supplier.website}</p>
                            </div>
                        )}
                        {supplier.tax_number && (
                            <div>
                                <p className="text-xs font-semibold text-slate-400">Tax number</p>
                                <p className="text-slate-700">{supplier.tax_number}</p>
                            </div>
                        )}
                    </div>

                    <RecordPaymentForm supplierId={supplier.id} />
                </div>

                <div className="lg:col-span-2">
                    <AccountStatement statement={statement} aging={aging} balanceLabel="you owe" />
                </div>
            </div>
        </BackOfficeLayout>
    );
}

/** Purchasing & Cash Vault Blueprint, part B — Dr Accounts Payable / Cr Cash or Bank. */
function RecordPaymentForm({ supplierId }: { supplierId: string }) {
    const form = useForm({
        amount: '',
        payment_date: new Date().toISOString().slice(0, 10),
        method: 'cash',
        reference: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/office/suppliers/${supplierId}/payments`, {
            preserveScroll: true,
            onSuccess: () => form.reset('amount', 'reference'),
        });
    };

    return (
        <form onSubmit={submit} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 space-y-3">
            <p className="text-sm font-semibold text-slate-800">Record a payment</p>
            <div>
                <label className="text-xs font-semibold text-slate-500">Amount</label>
                <input
                    type="number" step="0.01" min="0.01" required
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
                {form.errors.amount && <p className="text-xs text-red-500 mt-1">{form.errors.amount}</p>}
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Date</label>
                <input
                    type="date" required
                    value={form.data.payment_date}
                    onChange={(e) => form.setData('payment_date', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Method</label>
                <select
                    value={form.data.method}
                    onChange={(e) => form.setData('method', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                </select>
            </div>
            <div>
                <label className="text-xs font-semibold text-slate-500">Reference (optional)</label>
                <input
                    type="text"
                    value={form.data.reference}
                    onChange={(e) => form.setData('reference', e.target.value)}
                    className="mt-1 w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </div>
            <button type="submit" disabled={form.processing} className="btn-primary py-2 w-full disabled:opacity-50">
                {form.processing ? 'Saving…' : 'Record Payment'}
            </button>
        </form>
    );
}
