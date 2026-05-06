import React from 'react';

interface Props {
    label: string;
    value: string | number;
    sub?: string;
    color?: 'blue' | 'green' | 'amber' | 'purple' | 'violet';
    icon?: React.ReactNode;
}

const palette: Record<string, { accent: string }> = {
    blue:   { accent: 'bg-sky-100 text-sky-600' },
    green:  { accent: 'bg-emerald-100 text-emerald-600' },
    amber:  { accent: 'bg-amber-100 text-amber-600' },
    purple: { accent: 'bg-purple-100 text-purple-600' },
    violet: { accent: 'bg-violet-100 text-violet-600' },
};

export default function StatsCard({ label, value, sub, color = 'blue', icon }: Props) {
    const { accent } = palette[color];
    return (
        <div className="rounded-2xl border border-slate-100 bg-white shadow-sm p-5 flex flex-col gap-4">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium text-slate-500">{label}</p>
                {icon && (
                    <div className={`w-9 h-9 rounded-xl flex items-center justify-center ${accent}`}>
                        {icon}
                    </div>
                )}
            </div>
            <div>
                <p className="text-3xl font-bold tracking-tight text-slate-900">{value}</p>
                {sub && <p className="mt-1 text-xs text-slate-400">{sub}</p>}
            </div>
        </div>
    );
}
