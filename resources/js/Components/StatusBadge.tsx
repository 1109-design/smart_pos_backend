import React from 'react';

type Variant = 'green' | 'amber' | 'red' | 'blue' | 'gray' | 'violet';

const styles: Record<Variant, { badge: string; dot: string }> = {
    green:  { badge: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',  dot: 'bg-emerald-500' },
    amber:  { badge: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',        dot: 'bg-amber-500' },
    red:    { badge: 'bg-red-50 text-red-700 ring-1 ring-red-200',              dot: 'bg-red-500' },
    blue:   { badge: 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',              dot: 'bg-sky-500' },
    violet: { badge: 'bg-violet-50 text-violet-700 ring-1 ring-violet-200',     dot: 'bg-violet-500' },
    gray:   { badge: 'bg-slate-50 text-slate-600 ring-1 ring-slate-200',        dot: 'bg-slate-400' },
};

interface Props {
    label: string;
    variant?: Variant;
    dot?: boolean;
}

export default function StatusBadge({ label, variant = 'gray', dot = true }: Props) {
    const { badge, dot: dotColor } = styles[variant];
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${badge}`}>
            {dot && <span className={`w-1.5 h-1.5 rounded-full flex-shrink-0 ${dotColor}`} />}
            {label}
        </span>
    );
}
