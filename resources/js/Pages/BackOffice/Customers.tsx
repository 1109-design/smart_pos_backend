import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';

interface CustomerRow {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    loyalty_points: string;
    credit_balance: string;
    credit_limit: string;
    group: string | null;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    customers: Paginated<CustomerRow>;
    filters: { search: string };
}

export default function BackOfficeCustomers({ customers, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const runSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/office/customers', { search }, { preserveState: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Customers" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Customers</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Loyalty points and store credit, kept in sync from every till.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <form onSubmit={runSearch} className="mb-4">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search customers…"
                    className="w-full sm:w-80 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                />
            </form>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[640px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Name</th>
                                <th className="table-th">Contact</th>
                                <th className="table-th text-right">Loyalty points</th>
                                <th className="table-th text-right">Credit balance</th>
                                <th className="table-th">Group</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {customers.data.map((c) => (
                                <tr key={c.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">
                                        <Link href={`/office/customers/${c.id}`} className="hover:text-emerald-700 hover:underline">
                                            {c.name}
                                        </Link>
                                    </td>
                                    <td className="table-td text-slate-600">{c.phone ?? c.email ?? '—'}</td>
                                    <td className="table-td text-right text-slate-600">{Number(c.loyalty_points).toFixed(0)}</td>
                                    <td className="table-td text-right text-slate-600">
                                        {Number(c.credit_balance).toFixed(2)}
                                        {Number(c.credit_limit) > 0 && (
                                            <span className="text-slate-400"> / {Number(c.credit_limit).toFixed(2)}</span>
                                        )}
                                    </td>
                                    <td className="table-td text-slate-600">{c.group ?? '—'}</td>
                                </tr>
                            ))}
                            {customers.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="table-td text-center text-slate-400 py-10">No customers yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {customers.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {customers.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveState
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
