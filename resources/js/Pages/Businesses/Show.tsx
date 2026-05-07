import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';
import BusinessTabs from '@/Components/BusinessTabs';

interface SetupCredentials {
    admin_name: string;
    admin_email: string;
    admin_pin: string;
    pairing_code: string;
}

interface Business {
    id: string;
    pairing_code: string | null;
    business_name: string;
    owner_email: string;
    tier: string;
    is_active: boolean;
    currency_code: string;
    country: string | null;
    subscription_valid_until: string | null;
}

interface Props {
    business: Business;
    devicesCount: number;
    setup_credentials?: SetupCredentials;
}

const tierVariant = (tier: string): 'violet' | 'amber' | 'gray' => {
    if (tier === 'ultimate') return 'violet';
    if (tier === 'pro') return 'amber';
    return 'gray';
};

export default function BusinessShow({ business, devicesCount, setup_credentials }: Props) {
    const [credsDismissed, setCredsDismissed] = useState(false);

    return (
        <AppLayout>
            <Head title={business.business_name} />

            {setup_credentials && !credsDismissed && (
                <div className="mb-6 bg-amber-50 border border-amber-200 rounded-2xl p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-semibold text-amber-800 flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                                    <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clipRule="evenodd" />
                                </svg>
                                Save these credentials — they won't be shown again
                            </p>
                            <p className="text-xs text-amber-600 mt-1">
                                Use these to log in to the POS app for the first time.
                            </p>
                        </div>
                        <button
                            onClick={() => setCredsDismissed(true)}
                            className="text-amber-400 hover:text-amber-600 text-lg leading-none flex-shrink-0 w-6 h-6 flex items-center justify-center"
                        >
                            ×
                        </button>
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {[
                            { label: 'Admin Name',  value: setup_credentials.admin_name },
                            { label: 'Email',        value: setup_credentials.admin_email },
                            { label: 'PIN',          value: setup_credentials.admin_pin },
                            { label: 'Pairing Code', value: setup_credentials.pairing_code },
                        ].map(({ label, value }) => (
                            <div key={label} className="bg-white rounded-xl border border-amber-200 p-3">
                                <p className="text-xs text-amber-500 uppercase tracking-wide font-medium">{label}</p>
                                <p className="text-sm font-mono font-bold text-slate-800 mt-1 break-all">{value}</p>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Breadcrumb */}
            <div className="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <Link href="/businesses" className="hover:text-slate-600 transition-colors">Businesses</Link>
                <span>/</span>
                <span className="text-slate-600 font-medium">{business.business_name}</span>
            </div>

            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">{business.business_name}</h1>
                    <p className="text-sm text-slate-500 mt-1">{business.owner_email}</p>
                </div>
                <div className="flex items-center gap-2">
                    <StatusBadge
                        label={business.tier.charAt(0).toUpperCase() + business.tier.slice(1)}
                        variant={tierVariant(business.tier)}
                    />
                    <StatusBadge
                        label={business.is_active ? 'Active' : 'Inactive'}
                        variant={business.is_active ? 'green' : 'red'}
                    />
                    <Link href={`/businesses/${business.id}/edit`} className="btn-secondary ml-2">
                        Edit
                    </Link>
                </div>
            </div>

            {/* Tabs */}
            <BusinessTabs businessId={business.id} active="overview" devicesCount={devicesCount} />

            <div className="space-y-5">
                {/* Pairing Code */}
                <div className="bg-gradient-to-br from-violet-600 to-purple-700 rounded-2xl p-6 text-white shadow-lg shadow-violet-200">
                    <p className="text-xs font-semibold uppercase tracking-widest text-violet-200">Mobile App Pairing Code</p>
                    <div className="flex items-center gap-5 mt-3">
                        <p className="text-4xl font-bold tracking-widest font-mono">{business.pairing_code || '—'}</p>
                        <button
                            onClick={() => navigator.clipboard.writeText(business.pairing_code || '')}
                            className="text-sm bg-white/15 hover:bg-white/25 px-4 py-1.5 rounded-xl transition-colors font-medium"
                        >
                            Copy
                        </button>
                    </div>
                    <p className="text-xs text-violet-300 mt-3">Enter this code in the mobile app to connect this business</p>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
                    {[
                        { label: 'Business ID', value: business.id },
                        { label: 'Currency',    value: business.currency_code },
                        { label: 'Country',     value: business.country ?? '—' },
                        { label: 'Valid Until', value: business.subscription_valid_until ?? '— (Starter)' },
                    ].map(({ label, value }) => (
                        <div key={label} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
                            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">{label}</p>
                            <p className="text-sm font-medium text-slate-800 mt-1.5 break-all">{value}</p>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
