import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

type ApprovalStatus = 'pending' | 'approved' | 'rejected';

interface ApprovalRow {
    id: string;
    subject_type: string;
    subject_id: string;
    action: string;
    reason: string | null;
    payload_json: {
        reason?: string;
        po_number?: string;
        supplier_name?: string;
        // refund_transaction (till return / exchange)
        sale_number?: string | null;
        amount?: number;
        outcome?: 'refund' | 'exchange';
        items?: string;
        exchange_items?: string;
        net_amount?: number;
        settlement_method?: string | null;
        // stock_adjustment
        batch_ref?: string;
        lines?: {
            product_id: string;
            product_name: string;
            delta: number;
            movement_type: string;
            reason: string;
            old_qty?: number;
            new_qty?: number;
        }[];
        // void_transaction
        total?: number;
        // change_exchange_rate
        from_currency?: string;
        to_currency?: string;
        rate?: number;
        locked?: boolean;
        // reverse_pending_collection
        collector_name?: string;
        mode?: 'none' | 'partial' | string;
        items_change?: string;
        // set_customer_opening_balance
        customer_id?: string;
        customer_name?: string;
        // import_customer_opening_balances
        count?: number;
        // apply_discount
        product_name?: string;
        discount_amount?: number;
        discount_pct?: number;
    } | null;
    status: ApprovalStatus;
    requested_by: { id: string; name: string } | null;
    approver: { id: string; name: string } | null;
    approved_at: string | null;
    created_at: string;
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    requests: Paginated<ApprovalRow>;
    filters: { status: string };
}

/** What a till return actually does, from its approval payload. */
function ReturnDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    const isExchange = payload.outcome === 'exchange';
    const net = payload.net_amount;
    const money =
        net === undefined || Math.abs(net) <= 0.005
            ? isExchange
                ? 'Even exchange'
                : null
            : net > 0
              ? `Customer pays ${net.toFixed(2)}`
              : `Customer gets ${(-net).toFixed(2)} back`;

    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            <div>
                {isExchange ? 'Exchange' : 'Refund'}
                {payload.sale_number && <> · sale #{payload.sale_number}</>}
                {payload.amount !== undefined && <> · returned value {payload.amount.toFixed(2)}</>}
            </div>
            {payload.items && <div>Coming back: {payload.items}</div>}
            {payload.exchange_items && <div>Taking instead: {payload.exchange_items}</div>}
            {money && (
                <div>
                    {money}
                    {payload.settlement_method && <> by {payload.settlement_method}</>}
                </div>
            )}
            {payload.reason && <div>Reason: {payload.reason}</div>}
        </div>
    );
}

/** What a stock adjustment batch actually changes, from its approval payload. */
function StockAdjustmentDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    const lines = payload.lines ?? [];
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            {payload.batch_ref && <div>Batch {payload.batch_ref}</div>}
            {payload.reason && <div>Reason: {payload.reason}</div>}
            {lines.map((line, i) => (
                <div key={line.product_id ?? i}>
                    {line.product_name}{' '}
                    {line.old_qty !== undefined && line.new_qty !== undefined
                        ? `${line.old_qty} → ${line.new_qty} `
                        : ''}
                    <span className={line.delta > 0 ? 'text-emerald-600' : 'text-red-600'}>
                        ({line.delta > 0 ? '+' : ''}
                        {line.delta})
                    </span>
                    {line.reason && line.reason !== payload.reason && <> — {line.reason}</>}
                </div>
            ))}
        </div>
    );
}

/** What a voided sale actually was, from its approval payload. */
function VoidDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            <div>
                {payload.sale_number && <>Sale #{payload.sale_number}</>}
                {payload.total !== undefined && <> · {payload.total.toFixed(2)}</>}
            </div>
            {payload.reason && <div>Reason: {payload.reason}</div>}
        </div>
    );
}

/** The new rate an exchange-rate-change approval would apply. */
function ExchangeRateDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            {payload.from_currency && payload.to_currency && payload.rate !== undefined && (
                <div>
                    New rate: 1 {payload.from_currency} = {payload.rate} {payload.to_currency}
                </div>
            )}
            {payload.locked !== undefined && <div>Locked: {payload.locked ? 'Yes' : 'No'}</div>}
        </div>
    );
}

/** What a Pending Book collection reversal would correct. */
function ReversePendingCollectionDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            <div>
                {payload.sale_number && <>Sale #{payload.sale_number}</>}
                {payload.collector_name && <> · collector {payload.collector_name}</>}
            </div>
            {payload.mode && (
                <div>Correct to: {payload.mode === 'none' ? 'Nothing collected' : 'Partially collected'}</div>
            )}
            {payload.items_change && <div>Collected: {payload.items_change}</div>}
            {payload.reason && <div>Reason: {payload.reason}</div>}
        </div>
    );
}

/** The new opening balance a customer-opening-balance approval would set. */
function OpeningBalanceDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            <div>Customer: {payload.customer_name ?? payload.customer_id ?? '—'}</div>
            {payload.amount !== undefined && <div>New opening balance: {payload.amount.toFixed(2)}</div>}
        </div>
    );
}

/** How many customers an opening-balance import would affect. */
function ImportOpeningBalancesDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            {payload.count !== undefined && <div>Customers affected: {payload.count}</div>}
        </div>
    );
}

/** What a manager's discount override would apply. */
function DiscountDetails({ payload }: { payload: NonNullable<ApprovalRow['payload_json']> }) {
    return (
        <div className="text-xs font-normal text-slate-500 space-y-0.5 mt-0.5">
            {payload.product_name && <div>Product: {payload.product_name}</div>}
            {payload.discount_pct !== undefined && <div>Discount: {payload.discount_pct.toFixed(1)}%</div>}
            {payload.discount_amount !== undefined && <div>Discount amount: {payload.discount_amount.toFixed(2)}</div>}
        </div>
    );
}

const STATUS_STYLE: Record<ApprovalStatus, { label: string; variant: 'amber' | 'green' | 'red' }> = {
    pending: { label: 'Pending', variant: 'amber' },
    approved: { label: 'Approved', variant: 'green' },
    rejected: { label: 'Rejected', variant: 'red' },
};

const ACTION_LABELS: Record<string, string> = {
    void_transaction: 'Void sale',
    refund_transaction: 'Return / Exchange',
    change_exchange_rate: 'Exchange rate change',
    approve_purchase_order: 'Purchase order over threshold',
    stock_adjustment: 'Stock adjustment',
    reverse_pending_collection: 'Reverse Pending Book collection',
    set_customer_opening_balance: 'Set customer opening balance',
    import_customer_opening_balances: 'Import customer opening balances',
    apply_discount: 'Manager discount',
};

export default function BackOfficeApprovals({ requests, filters }: Props) {
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };

    const decide = (id: string, decision: 'approve' | 'reject') => {
        const reason = window.prompt(decision === 'reject' ? 'Reason for rejecting (optional):' : 'Note (optional):') ?? undefined;
        router.post(`/office/approvals/${id}/${decision}`, { reason }, { preserveScroll: true });
    };

    return (
        <BackOfficeLayout>
            <Head title="Approvals" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Approvals</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Actions raised at a till with no manager on site to approve them there — void/refund requests and
                    exchange-rate changes wait here until an owner or manager reviews them remotely.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="mb-4">
                <select
                    value={filters.status}
                    onChange={(e) => router.get('/office/approvals', { status: e.target.value }, { preserveState: true })}
                    className="text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                >
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="all">All</option>
                </select>
            </div>

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="bg-slate-50">
                                <th className="table-th">Action</th>
                                <th className="table-th">Requested by</th>
                                <th className="table-th">Raised</th>
                                <th className="table-th">Status</th>
                                <th className="table-th">Resolved by</th>
                                <th className="table-th text-right">Decision</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {requests.data.map((r) => (
                                <tr key={r.id} className="hover:bg-slate-50/60">
                                    <td className="table-td font-medium text-slate-900">
                                        {ACTION_LABELS[r.action] ?? r.action}
                                        {r.action === 'approve_purchase_order' && r.payload_json?.po_number && (
                                            <div className="text-xs font-normal text-slate-500">
                                                {r.payload_json.po_number} — {r.payload_json.supplier_name}
                                                {r.payload_json.reason && <> ({r.payload_json.reason})</>}
                                            </div>
                                        )}
                                        {r.action === 'refund_transaction' && r.payload_json && (
                                            <ReturnDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'stock_adjustment' && r.payload_json && (
                                            <StockAdjustmentDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'void_transaction' && r.payload_json && (
                                            <VoidDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'change_exchange_rate' && r.payload_json && (
                                            <ExchangeRateDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'reverse_pending_collection' && r.payload_json && (
                                            <ReversePendingCollectionDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'set_customer_opening_balance' && r.payload_json && (
                                            <OpeningBalanceDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'import_customer_opening_balances' && r.payload_json && (
                                            <ImportOpeningBalancesDetails payload={r.payload_json} />
                                        )}
                                        {r.action === 'apply_discount' && r.payload_json && (
                                            <DiscountDetails payload={r.payload_json} />
                                        )}
                                    </td>
                                    <td className="table-td text-slate-600">{r.requested_by?.name ?? '—'}</td>
                                    <td className="table-td text-slate-600 whitespace-nowrap">{new Date(r.created_at).toLocaleString()}</td>
                                    <td className="table-td"><StatusBadge label={STATUS_STYLE[r.status].label} variant={STATUS_STYLE[r.status].variant} /></td>
                                    <td className="table-td text-slate-600">{r.approver?.name ?? '—'}</td>
                                    <td className="table-td text-right space-x-3">
                                        {r.status === 'pending' ? (
                                            <>
                                                <button
                                                    onClick={() => decide(r.id, 'approve')}
                                                    className="text-xs font-semibold text-emerald-600 hover:text-emerald-800"
                                                >
                                                    Approve
                                                </button>
                                                <button
                                                    onClick={() => decide(r.id, 'reject')}
                                                    className="text-xs font-semibold text-red-600 hover:text-red-800"
                                                >
                                                    Reject
                                                </button>
                                            </>
                                        ) : (
                                            <span className="text-xs text-slate-300">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {requests.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="table-td text-center text-slate-400 py-10">No requests here.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {requests.links.length > 3 && (
                    <div className="px-6 py-4 border-t border-slate-100 flex flex-wrap gap-1">
                        {requests.links.map((link, i) =>
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
