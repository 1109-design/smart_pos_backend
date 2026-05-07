import React from 'react';
import { Link } from '@inertiajs/react';

type TabKey = 'overview' | 'devices' | 'users' | 'subscription' | 'activation-codes';

interface Props {
    businessId: string;
    active: TabKey;
    devicesCount?: number;
}

const TABS: { key: TabKey; label: string; suffix: string }[] = [
    { key: 'overview',          label: 'Overview',          suffix: '' },
    { key: 'devices',           label: 'Devices',           suffix: '/devices' },
    { key: 'users',             label: 'Users',             suffix: '/users' },
    { key: 'subscription',      label: 'Subscription',      suffix: '/subscription' },
    { key: 'activation-codes',  label: 'Activation Codes',  suffix: '/activation-codes' },
];

export default function BusinessTabs({ businessId, active, devicesCount }: Props) {
    const base = `/businesses/${businessId}`;

    const cls = (key: TabKey) =>
        `px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
            active === key
                ? 'border-violet-600 text-violet-700'
                : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
        }`;

    return (
        <div className="flex border-b border-slate-200 mb-6 gap-1 overflow-x-auto scrollbar-none -mx-4 px-4 sm:mx-0 sm:px-0">
            {TABS.map(({ key, label, suffix }) => (
                <Link
                    key={key}
                    href={`${base}${suffix}`}
                    className={cls(key)}
                >
                    {label}
                    {key === 'devices' && devicesCount !== undefined && (
                        <span className="ml-1 text-xs text-slate-400">({devicesCount})</span>
                    )}
                </Link>
            ))}
        </div>
    );
}
