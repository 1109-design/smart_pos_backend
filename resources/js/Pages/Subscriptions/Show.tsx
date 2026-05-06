import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import BusinessTabs from '@/Components/BusinessTabs';

interface Business {
    id: string;
    business_name: string;
    tier: string;
    subscription_valid_until: string | null;
    is_active: boolean;
}

interface TierInfo {
    key: string;
    label: string;
    price: string;
    features: string[];
    sort_order: number;
}

interface HistoryEntry {
    id: number;
    tier: string;
    event_type: string;
    previous_tier: string | null;
    subscription_valid_until: string | null;
    webhook_event_id: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

interface Props {
    business: Business;
    tiers: Record<string, TierInfo>;
    history: HistoryEntry[];
}

const accentColors = [
    { bg: 'bg-violet-600', ring: 'ring-indigo-400', light: 'bg-violet-50', text: 'text-violet-700', border: 'border-indigo-300' },
    { bg: 'bg-violet-600', ring: 'ring-violet-400', light: 'bg-violet-50', text: 'text-violet-700', border: 'border-violet-300' },
    { bg: 'bg-purple-600', ring: 'ring-purple-400', light: 'bg-purple-50', text: 'text-purple-700', border: 'border-purple-300' },
    { bg: 'bg-fuchsia-600', ring: 'ring-fuchsia-400', light: 'bg-fuchsia-50', text: 'text-fuchsia-700', border: 'border-fuchsia-300' },
];

const EVENT_META: Record<string, { label: string; color: string; icon: React.ReactNode }> = {
    INITIAL_PURCHASE: {
        label: 'Initial Purchase',
        color: 'bg-emerald-100 text-emerald-700',
        icon: (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
            </svg>
        ),
    },
    RENEWAL: {
        label: 'Renewal',
        color: 'bg-sky-100 text-sky-700',
        icon: (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582M20 20v-5h-.581M5.535 19A9 9 0 1018.465 5" />
            </svg>
        ),
    },
    PRODUCT_CHANGE: {
        label: 'Plan Change',
        color: 'bg-amber-100 text-amber-700',
        icon: (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4" />
            </svg>
        ),
    },
    EXPIRATION: {
        label: 'Expired',
        color: 'bg-red-100 text-red-700',
        icon: (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
    },
    CANCELLATION: {
        label: 'Cancelled',
        color: 'bg-red-100 text-red-700',
        icon: (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        ),
    },
    MANUAL_OVERRIDE: {
        label: 'Manual Override',
        color: 'bg-orange-100 text-orange-700',
        icon: (
            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
        ),
    },
};

function formatDate(dt: string | null): string {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dt: string): string {
    return new Date(dt).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

export default function SubscriptionShow({ business, tiers, history }: Props) {
    const tiersArray = Object.values(tiers);
    const currentTier = tiers[business.tier] ?? tiersArray[0];
    const currentAccent = accentColors[tiersArray.findIndex(t => t.key === business.tier) % accentColors.length] ?? accentColors[0];

    const { data, setData, put, processing } = useForm({
        tier: business.tier,
        subscription_valid_until: business.subscription_valid_until?.slice(0, 10) ?? '',
    });

    const selectedAccent = accentColors[tiersArray.findIndex(t => t.key === data.tier) % accentColors.length] ?? accentColors[0];

    return (
        <AppLayout>
            <Head title={`Subscription — ${business.business_name}`} />

            <div className="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <Link href="/businesses" className="hover:text-slate-600 transition-colors">Businesses</Link>
                <span>/</span>
                <Link href={`/businesses/${business.id}`} className="hover:text-slate-600 transition-colors">
                    {business.business_name}
                </Link>
                <span>/</span>
                <span className="text-slate-600 font-medium">Subscription</span>
            </div>

            <div className="flex items-start justify-between mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{business.business_name}</h1>
            </div>

            <BusinessTabs businessId={business.id} active="subscription" />

            <div className="mb-6">
                <h2 className="text-lg font-semibold text-slate-800">Subscription</h2>
                <p className="text-sm text-slate-500 mt-1">Manage the subscription plan for this business</p>
            </div>

            <div className="grid grid-cols-1 gap-8 lg:grid-cols-5">

                {/* ── Current plan card ─────────────────────────────── */}
                <div className="lg:col-span-2">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Current Plan</p>
                    <div className="rounded-2xl overflow-hidden border border-slate-200 shadow-sm">
                        <div className={`${currentAccent.bg} px-6 pt-6 pb-5`}>
                            <span className="inline-flex items-center bg-white/20 text-white text-xs font-medium px-2.5 py-1 rounded-full mb-3">
                                <span className="font-mono">{business.tier}</span>
                            </span>
                            <p className="text-white text-2xl font-bold leading-tight">{currentTier?.label}</p>
                            <p className="text-white/80 text-sm mt-0.5">{currentTier?.price}</p>
                        </div>
                        <div className="bg-white px-6 py-5">
                            {business.subscription_valid_until && (
                                <div className="flex items-center gap-2 mb-4 text-sm text-slate-500 bg-slate-50 rounded-xl px-3 py-2">
                                    <svg className="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    Valid until <span className="font-medium text-slate-700">{business.subscription_valid_until.slice(0, 10)}</span>
                                </div>
                            )}
                            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">What's included</p>
                            <ul className="space-y-2">
                                {currentTier?.features.map(f => (
                                    <li key={f} className="flex items-start gap-2 text-sm text-slate-700">
                                        <svg className="w-4 h-4 text-green-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                        {f}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>

                {/* ── Manual override ───────────────────────────────── */}
                <div className="lg:col-span-3">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Manual Override</p>
                    <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                        <p className="text-xs text-slate-400 mb-5">
                            Overrides are normally managed by RevenueCat webhooks. Use this only for manual adjustments.
                        </p>

                        <form
                            onSubmit={(e) => { e.preventDefault(); put(`/businesses/${business.id}/subscription`); }}
                            className="space-y-5"
                        >
                            {/* Tier radio cards */}
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-3">Select Tier</label>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                    {tiersArray.map((t, idx) => {
                                        const accent = accentColors[idx % accentColors.length];
                                        const selected = data.tier === t.key;
                                        return (
                                            <button
                                                key={t.key}
                                                type="button"
                                                onClick={() => setData('tier', t.key)}
                                                className={`text-left rounded-xl border-2 px-4 py-3 transition-all ${
                                                    selected
                                                        ? `${accent.border} ${accent.light} ring-2 ${accent.ring} ring-offset-1`
                                                        : 'border-slate-200 hover:border-slate-300 bg-white'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className={`text-sm font-semibold ${selected ? accent.text : 'text-slate-800'}`}>{t.label}</span>
                                                    {selected && (
                                                        <svg className={`w-4 h-4 ${accent.text}`} fill="currentColor" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                                        </svg>
                                                    )}
                                                </div>
                                                <p className="text-xs text-slate-500 mt-0.5">{t.price}</p>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Valid Until */}
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Valid Until</label>
                                <input
                                    type="date"
                                    value={data.subscription_valid_until}
                                    onChange={(e) => setData('subscription_valid_until', e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                                />
                                <p className="text-xs text-slate-400 mt-1">Leave empty for Starter (free forever)</p>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className={`w-full ${selectedAccent.bg} text-white text-sm font-medium py-2.5 rounded-xl hover:opacity-90 disabled:opacity-50 transition-opacity`}
                            >
                                Save Override
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            {/* ── Subscription History ────────────────────────────────── */}
            <div className="mt-10">
                <div className="flex items-center justify-between mb-4">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                        Subscription History
                    </p>
                    <span className="text-xs text-slate-400">{history.length} event{history.length !== 1 ? 's' : ''}</span>
                </div>

                {history.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-slate-200 px-6 py-12 text-center">
                        <svg className="w-10 h-10 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p className="text-sm text-slate-400">No subscription events recorded yet.</p>
                        <p className="text-xs text-slate-300 mt-1">Events will appear here after subscription changes via webhook or manual override.</p>
                    </div>
                ) : (
                    <div className="relative">
                        {/* Timeline line */}
                        <div className="absolute left-5 top-0 bottom-0 w-px bg-slate-100 z-0" />

                        <div className="space-y-3">
                            {history.map((entry, i) => {
                                const meta = EVENT_META[entry.event_type] ?? {
                                    label: entry.event_type,
                                    color: 'bg-slate-100 text-slate-600',
                                    icon: null,
                                };
                                const isFirst = i === 0;

                                return (
                                    <div key={entry.id} className="relative flex gap-4 pl-12">
                                        {/* Timeline dot */}
                                        <div className={`absolute left-3.5 top-4 w-3 h-3 rounded-full border-2 border-white z-10 ${isFirst ? 'bg-violet-500' : 'bg-gray-300'}`} />

                                        {/* Card */}
                                        <div className={`flex-1 bg-white rounded-xl border ${isFirst ? 'border-indigo-100 shadow-sm' : 'border-slate-100'} p-4`}>
                                            <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    {/* Event type badge */}
                                                    <span className={`inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full ${meta.color}`}>
                                                        {meta.icon}
                                                        {meta.label}
                                                    </span>

                                                    {/* Tier change */}
                                                    {entry.previous_tier && entry.previous_tier !== entry.tier ? (
                                                        <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                                                            <span className="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-600">{entry.previous_tier}</span>
                                                            <svg className="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                                            </svg>
                                                            <span className="font-mono bg-violet-50 px-1.5 py-0.5 rounded text-violet-700">{entry.tier}</span>
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center text-xs text-slate-500">
                                                            <span className="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-600">{entry.tier}</span>
                                                        </span>
                                                    )}
                                                </div>

                                                <span className="text-xs text-slate-400 whitespace-nowrap">{formatDateTime(entry.created_at)}</span>
                                            </div>

                                            <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                                                {entry.subscription_valid_until && (
                                                    <span>
                                                        Valid until{' '}
                                                        <span className="font-medium text-slate-700">{formatDate(entry.subscription_valid_until)}</span>
                                                    </span>
                                                )}
                                                {entry.webhook_event_id && (
                                                    <span>
                                                        Webhook ID{' '}
                                                        <span className="font-mono text-slate-600">{entry.webhook_event_id}</span>
                                                    </span>
                                                )}
                                            </div>

                                            {entry.metadata && Object.keys(entry.metadata).length > 0 && (
                                                <details className="mt-3">
                                                    <summary className="text-xs text-slate-400 cursor-pointer hover:text-slate-600 select-none">
                                                        Metadata
                                                    </summary>
                                                    <pre className="mt-2 text-xs bg-slate-50 rounded-xl p-3 overflow-x-auto text-slate-600 leading-relaxed">
                                                        {JSON.stringify(entry.metadata, null, 2)}
                                                    </pre>
                                                </details>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
