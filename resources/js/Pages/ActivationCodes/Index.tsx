import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';
import BusinessTabs from '@/Components/BusinessTabs';

interface Business {
    id: string;
    business_name: string;
    tier: string;
}

interface Device {
    id: number;
    name: string;
    device_identifier: string;
}

interface ActivationCode {
    id: number;
    code: string;
    tier: string;
    status: 'pending' | 'used' | 'expired';
    expires_at: string | null;
    used_at: string | null;
    created_at: string;
    device: Device | null;
    metadata: Record<string, unknown> | null;
}

interface Props {
    business: Business;
    codes: ActivationCode[];
}

const STATUS_VARIANT: Record<string, 'green' | 'gray' | 'red'> = {
    pending:  'green',
    used:     'gray',
    expired:  'red',
};

function formatDate(dt: string | null): string {
    if (!dt) return '—';
    return new Date(dt).toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = React.useState(false);
    return (
        <button
            onClick={() => {
                navigator.clipboard.writeText(text);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }}
            className="ml-2 text-xs text-violet-500 hover:text-violet-700 transition-colors"
        >
            {copied ? '✓ Copied' : 'Copy'}
        </button>
    );
}

export default function ActivationCodesIndex({ business, codes }: Props) {
    // Read Inertia flash message
    const { props } = usePage();
    const flash = (props as { flash?: { success?: string } }).flash;

    const pending = codes.filter(c => c.status === 'pending');
    const rest    = codes.filter(c => c.status !== 'pending');

    function generate() {
        if (confirm('Generate a new activation code for this business?')) {
            router.post(`/businesses/${business.id}/activation-codes`);
        }
    }

    return (
        <AppLayout>
            <Head title={`Activation Codes — ${business.business_name}`} />

            {/* Breadcrumb + header */}
            <div className="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <Link href="/businesses" className="hover:text-slate-600 transition-colors">Businesses</Link>
                <span>/</span>
                <Link href={`/businesses/${business.id}`} className="hover:text-slate-600 transition-colors">
                    {business.business_name}
                </Link>
                <span>/</span>
                <span className="text-slate-600 font-medium">Activation Codes</span>
            </div>

            <div className="flex items-start justify-between mb-6">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{business.business_name}</h1>
            </div>

            <BusinessTabs businessId={business.id} active="activation-codes" />

            <div className="mb-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-800">Activation Codes</h2>
                        <p className="text-sm text-slate-500 mt-0.5">
                            One-time codes used to unlock the mobile app on a device
                        </p>
                    </div>
                    <button
                        onClick={generate}
                        className="inline-flex items-center gap-2 bg-violet-600 text-white text-sm font-medium px-4 py-2 rounded-xl hover:bg-violet-700 transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Generate Code
                    </button>
                </div>
            </div>


{/* Flash message */}
            {flash?.success && (
                <div className="mb-6 flex items-start gap-3 bg-emerald-50 border border-emerald-200 rounded-xl px-5 py-4">
                    <svg className="w-5 h-5 text-emerald-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <p className="text-sm text-emerald-800">{flash.success}</p>
                </div>
            )}

            {/* Pending codes — highlighted */}
            {pending.length > 0 && (
                <div className="mb-8">
                    <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">
                        Ready to Use ({pending.length})
                    </p>
                    <div className="space-y-3">
                        {pending.map(c => (
                            <div key={c.id} className="bg-gradient-to-r from-violet-50 to-violet-50 border border-violet-200 rounded-xl px-6 py-4 flex items-center justify-between gap-4">
                                <div className="flex items-center gap-4">
                                    <div className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse flex-shrink-0" />
                                    <div>
                                        <div className="flex items-center">
                                            <span className="font-mono text-xl font-bold text-violet-800 tracking-widest">
                                                {c.code}
                                            </span>
                                            <CopyButton text={c.code} />
                                        </div>
                                        <div className="flex items-center gap-3 mt-1 text-xs text-slate-500">
                                            <span>Tier: <span className="font-medium text-slate-700">{c.tier}</span></span>
                                            {c.expires_at
                                                ? <span>Expires: <span className="font-medium text-slate-700">{formatDate(c.expires_at)}</span></span>
                                                : <span className="text-slate-400">No expiry</span>
                                            }
                                            <span>Generated: {formatDate(c.created_at)}</span>
                                            {c.metadata?.generated_by != null && (
                                                <span className="italic text-slate-400">via {String(c.metadata.generated_by).replace('_', ' ')}</span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <StatusBadge label="Pending" variant="green" />
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* History table */}
            <div>
                <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">
                    History ({rest.length})
                </p>

                {codes.length === 0 ? (
                    <div className="bg-white rounded-xl border border-slate-200 px-6 py-12 text-center">
                        <svg className="w-10 h-10 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                        </svg>
                        <p className="text-sm text-slate-400">No activation codes yet.</p>
                        <p className="text-xs text-slate-300 mt-1">
                            Codes are generated automatically on subscription purchase, or manually using the button above.
                        </p>
                    </div>
                ) : rest.length === 0 ? (
                    <div className="bg-white rounded-xl border border-dashed border-slate-200 px-6 py-6 text-center">
                        <p className="text-sm text-slate-400">No used or expired codes to show.</p>
                    </div>
                ) : (
                    <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-400 text-xs uppercase tracking-wider border-b border-slate-100">
                                    <th className="px-5 py-3">Code</th>
                                    <th className="px-5 py-3">Tier</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Device</th>
                                    <th className="px-5 py-3">Used At</th>
                                    <th className="px-5 py-3">Expires</th>
                                    <th className="px-5 py-3">Generated</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rest.map(c => (
                                    <tr key={c.id} className="border-b border-gray-50 hover:bg-slate-50">
                                        <td className="px-5 py-3 font-mono text-xs text-slate-600 tracking-wider">{c.code}</td>
                                        <td className="px-5 py-3 text-slate-600 capitalize">{c.tier}</td>
                                        <td className="px-5 py-3">
                                            <StatusBadge
                                                label={c.status.charAt(0).toUpperCase() + c.status.slice(1)}
                                                variant={STATUS_VARIANT[c.status] ?? 'gray'}
                                            />
                                        </td>
                                        <td className="px-5 py-3 text-slate-500">
                                            {c.device ? (
                                                <span title={c.device.device_identifier}>{c.device.name}</span>
                                            ) : '—'}
                                        </td>
                                        <td className="px-5 py-3 text-slate-500">{formatDate(c.used_at)}</td>
                                        <td className="px-5 py-3 text-slate-500">{formatDate(c.expires_at)}</td>
                                        <td className="px-5 py-3 text-slate-400 text-xs">{formatDate(c.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
