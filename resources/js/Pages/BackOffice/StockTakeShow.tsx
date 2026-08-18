import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type TakeStatus = 'draft' | 'in_progress' | 'pending_approval' | 'approved' | 'rejected' | 'cancelled';

interface Item {
    id: string;
    product_name: string;
    system_qty: string;
    counted_qty: string | null;
    notes: string | null;
}

interface StockTake {
    id: string;
    title: string;
    status: TakeStatus;
    notes: string | null;
    review_comment: string | null;
    location: { id: string; name: string } | null;
    items: Item[];
    created_at: string;
}

interface Props {
    stock_take: StockTake;
}

const STATUS_STYLE: Record<TakeStatus, { label: string; variant: 'amber' | 'blue' | 'violet' | 'green' | 'red' | 'gray' }> = {
    draft: { label: 'Draft', variant: 'gray' },
    in_progress: { label: 'Counting', variant: 'amber' },
    pending_approval: { label: 'Pending Review', variant: 'violet' },
    approved: { label: 'Approved', variant: 'green' },
    rejected: { label: 'Rejected', variant: 'red' },
    cancelled: { label: 'Cancelled', variant: 'gray' },
};

function variance(item: Item): number {
    return Number(item.counted_qty ?? item.system_qty) - Number(item.system_qty);
}

export default function BackOfficeStockTakeShow({ stock_take }: Props) {
    const [comment, setComment] = useState(stock_take.review_comment ?? '');
    const [pending, setPending] = useState(false);

    const canReview = stock_take.status === 'pending_approval';
    const diffCount = stock_take.items.filter((i) => variance(i) !== 0).length;

    const act = (action: 'approve' | 'reject' | 'reopen') => {
        setPending(true);
        router.post(
            `/office/stocktakes/${stock_take.id}/${action}`,
            { review_comment: comment },
            { preserveScroll: true, onFinish: () => setPending(false) }
        );
    };

    return (
        <BackOfficeLayout>
            <Head title={stock_take.title} />

            <Link href="/office/stocktakes" className="text-xs font-semibold text-slate-500 hover:text-slate-700">
                ← All stocktakes
            </Link>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-3 mb-6">
                <div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{stock_take.title}</h1>
                        <StatusBadge label={STATUS_STYLE[stock_take.status].label} variant={STATUS_STYLE[stock_take.status].variant} />
                    </div>
                    <p className="text-sm text-slate-500 mt-1">
                        {stock_take.location?.name ?? 'No location'} · {diffCount} item{diffCount === 1 ? '' : 's'} with a variance
                    </p>
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden mb-6">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[600px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Product</th>
                                <th className="table-th text-right">System</th>
                                <th className="table-th text-right">Counted</th>
                                <th className="table-th text-right">Variance</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {stock_take.items.map((item) => {
                                const v = variance(item);
                                return (
                                    <tr key={item.id} className={v !== 0 ? 'bg-amber-50/40' : undefined}>
                                        <td className="table-td text-slate-700">{item.product_name}</td>
                                        <td className="table-td text-right text-slate-600">{Number(item.system_qty).toFixed(2)}</td>
                                        <td className="table-td text-right text-slate-600">
                                            {item.counted_qty !== null ? Number(item.counted_qty).toFixed(2) : '—'}
                                        </td>
                                        <td className={`table-td text-right font-semibold ${v > 0 ? 'text-emerald-600' : v < 0 ? 'text-red-500' : 'text-slate-400'}`}>
                                            {v > 0 ? '+' : ''}{v.toFixed(2)}
                                        </td>
                                    </tr>
                                );
                            })}
                            {stock_take.items.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="table-td text-center text-slate-400 py-10">No items counted yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {canReview ? (
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                    <p className="text-sm font-semibold text-slate-800 mb-1">Review this count</p>
                    <p className="text-xs text-slate-500 mb-3">
                        Approving writes a stock adjustment for every item above with a variance, updating every till on
                        its next sync. Rejecting or sending it back changes nothing yet.
                    </p>
                    <textarea
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                        placeholder="Comment (optional)"
                        rows={2}
                        className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 mb-3 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    />
                    <div className="flex flex-wrap gap-2">
                        <button onClick={() => act('approve')} disabled={pending} className="btn-primary py-2 disabled:opacity-50">
                            {pending ? 'Working…' : 'Approve & Adjust Stock'}
                        </button>
                        <button
                            onClick={() => act('reopen')}
                            disabled={pending}
                            className="text-sm px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                        >
                            Send Back for Correction
                        </button>
                        <button
                            onClick={() => act('reject')}
                            disabled={pending}
                            className="text-sm px-4 py-2 rounded-xl text-red-500 hover:bg-red-50 disabled:opacity-50"
                        >
                            Reject
                        </button>
                    </div>
                </div>
            ) : stock_take.review_comment ? (
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                    <p className="text-xs font-semibold text-slate-500 mb-1">Review comment</p>
                    <p className="text-sm text-slate-700">{stock_take.review_comment}</p>
                </div>
            ) : null}
        </BackOfficeLayout>
    );
}
