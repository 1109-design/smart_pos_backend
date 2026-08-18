import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import StatusBadge from '@/Components/StatusBadge';

interface LocationHealth {
    id: string;
    name: string;
    type: 'shop' | 'warehouse';
    low_stock_count: number;
    surplus_count: number;
    sku_count: number;
}

interface Suggestion {
    product_id: string;
    product_name: string;
    from_location_id: string;
    from_location_name: string;
    to_location_id: string;
    to_location_name: string;
    shop_quantity: number;
    warehouse_quantity: number;
    suggested_qty: number;
}

interface PendingTransfer {
    id: string;
    transfer_number: string;
    from: string | null;
    to: string | null;
    created_at?: string;
    dispatched_at?: string | null;
    stuck?: boolean;
}

interface Props {
    stock_health: LocationHealth[];
    suggestions: Suggestion[];
    pending_actions: {
        awaiting_approval: PendingTransfer[];
        awaiting_receipt: PendingTransfer[];
    };
}

export default function BackOfficeStoreman({ stock_health, suggestions, pending_actions }: Props) {
    const [requesting, setRequesting] = useState<string | null>(null);
    const { flash, errors } = usePage().props as unknown as {
        flash: { success: string | null };
        errors: Record<string, string>;
    };

    const requestSuggestion = (s: Suggestion) => {
        const key = `${s.product_id}:${s.to_location_id}`;
        setRequesting(key);
        router.post(
            '/office/storeman/suggested-transfer',
            {
                product_id: s.product_id,
                from_location_id: s.from_location_id,
                to_location_id: s.to_location_id,
                qty: s.suggested_qty,
            },
            { preserveScroll: true, onFinish: () => setRequesting(null) }
        );
    };

    return (
        <BackOfficeLayout>
            <Head title="Storeman" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Storeman</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Stock health across every location, transfers waiting on you, and where the warehouse can bail out a
                    low shop. Built on the same transfer engine as the Transfers page — nothing here moves stock on its
                    own until you approve it.
                </p>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}
            {errors?.transfer && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{errors.transfer}</div>
            )}

            {/* Stock health per location */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 mb-6">
                {stock_health.map((loc) => (
                    <div key={loc.id} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                        <div className="flex items-start justify-between gap-2 mb-3">
                            <p className="text-sm font-semibold text-slate-900">{loc.name}</p>
                            <StatusBadge label={loc.type === 'warehouse' ? 'Warehouse' : 'Shop'} variant={loc.type === 'warehouse' ? 'violet' : 'blue'} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <p className="text-xl font-bold text-red-500">{loc.low_stock_count}</p>
                                <p className="text-xs text-slate-500">Low stock SKUs</p>
                            </div>
                            <div>
                                <p className="text-xl font-bold text-emerald-600">{loc.surplus_count}</p>
                                <p className="text-xs text-slate-500">Surplus SKUs</p>
                            </div>
                        </div>
                        <p className="text-xs text-slate-400 mt-3">{loc.sku_count} tracked SKU{loc.sku_count === 1 ? '' : 's'} total</p>
                    </div>
                ))}
                {stock_health.length === 0 && (
                    <p className="col-span-full text-center text-sm text-slate-400 py-10">No active locations yet.</p>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Pending actions */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-800">Needs your attention</p>
                    </div>
                    <div className="divide-y divide-slate-50 max-h-[420px] overflow-y-auto">
                        {pending_actions.awaiting_approval.map((t) => (
                            <div key={t.id} className="px-5 py-3 flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm text-slate-700">
                                        <span className="font-medium">{t.transfer_number}</span> · {t.from} → {t.to}
                                    </p>
                                    <p className="text-xs text-slate-400">Awaiting approval</p>
                                </div>
                                <Link href="/office/transfers" className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex-shrink-0">
                                    Review
                                </Link>
                            </div>
                        ))}
                        {pending_actions.awaiting_receipt.map((t) => (
                            <div key={t.id} className="px-5 py-3 flex items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm text-slate-700">
                                        <span className="font-medium">{t.transfer_number}</span> · {t.from} → {t.to}
                                    </p>
                                    <p className="text-xs text-slate-400 flex items-center gap-1.5">
                                        Awaiting receipt
                                        {t.stuck && <StatusBadge label="Stuck in transit" variant="red" dot={false} />}
                                    </p>
                                </div>
                                <Link href="/office/transfers" className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex-shrink-0">
                                    Review
                                </Link>
                            </div>
                        ))}
                        {pending_actions.awaiting_approval.length === 0 && pending_actions.awaiting_receipt.length === 0 && (
                            <p className="px-5 py-8 text-center text-sm text-slate-400">Nothing waiting on you right now.</p>
                        )}
                    </div>
                </div>

                {/* Suggested transfers */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100">
                        <p className="text-sm font-semibold text-slate-800">Suggested transfers</p>
                        <p className="text-xs text-slate-500 mt-0.5">A shop is low, its warehouse has surplus of the same item.</p>
                    </div>
                    <div className="divide-y divide-slate-50 max-h-[420px] overflow-y-auto">
                        {suggestions.map((s) => {
                            const key = `${s.product_id}:${s.to_location_id}`;
                            return (
                                <div key={key} className="px-5 py-3 flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-700 truncate">{s.product_name}</p>
                                        <p className="text-xs text-slate-400">
                                            {s.from_location_name} ({s.warehouse_quantity}) → {s.to_location_name} ({s.shop_quantity}) · suggest {s.suggested_qty}
                                        </p>
                                    </div>
                                    <button
                                        onClick={() => requestSuggestion(s)}
                                        disabled={requesting === key}
                                        className="text-xs font-semibold text-emerald-600 hover:text-emerald-800 flex-shrink-0 disabled:opacity-50"
                                    >
                                        {requesting === key ? 'Requesting…' : 'Request Transfer'}
                                    </button>
                                </div>
                            );
                        })}
                        {suggestions.length === 0 && (
                            <p className="px-5 py-8 text-center text-sm text-slate-400">No suggestions right now — stock looks balanced.</p>
                        )}
                    </div>
                </div>
            </div>
        </BackOfficeLayout>
    );
}
