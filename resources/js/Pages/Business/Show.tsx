import React, { useEffect, useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';

type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';
interface TimeRange {
    open: string;
    close: string;
}

interface Business {
    name: string;
    tagline: string | null;
    logoUrl: string | null;
    primaryColor: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    whatsapp: string | null;
    facebookUrl: string | null;
    instagramUrl: string | null;
    about: string | null;
    openingHours: Partial<Record<DayKey, TimeRange[]>> | null;
    latitude: number | null;
    longitude: number | null;
    timezone: string;
}

interface Branch {
    name: string;
    address: string | null;
    phone: string | null;
}

interface Props {
    business: Business;
    branches: Branch[];
    pageUrl: string;
}

const DAYS: { key: DayKey; label: string }[] = [
    { key: 'mon', label: 'Monday' },
    { key: 'tue', label: 'Tuesday' },
    { key: 'wed', label: 'Wednesday' },
    { key: 'thu', label: 'Thursday' },
    { key: 'fri', label: 'Friday' },
    { key: 'sat', label: 'Saturday' },
    { key: 'sun', label: 'Sunday' },
];

const FALLBACK_COLOR = '#4f46e5';

function safeColor(color: string | null): string {
    return color && /^#[0-9a-f]{6}$/i.test(color) ? color : FALLBACK_COLOR;
}

function toMinutes(hhmm: string): number {
    const [h, m] = hhmm.split(':').map(Number);
    return (h || 0) * 60 + (m || 0);
}

function formatTime(hhmm: string): string {
    const mins = toMinutes(hhmm);
    const h = Math.floor(mins / 60) % 24;
    const m = mins % 60;
    const suffix = h < 12 ? 'am' : 'pm';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return m === 0 ? `${h12}${suffix}` : `${h12}:${String(m).padStart(2, '0')}${suffix}`;
}

/** Day key and minutes-since-midnight right now in the business's own timezone. */
function nowIn(timezone: string): { day: DayKey; minutes: number } {
    let parts: Intl.DateTimeFormatPart[];
    try {
        parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: timezone,
            weekday: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(new Date());
    } catch {
        parts = new Intl.DateTimeFormat('en-GB', { weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(
            new Date(),
        );
    }
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
    const day = get('weekday').slice(0, 3).toLowerCase() as DayKey;
    return { day, minutes: Number(get('hour')) * 60 + Number(get('minute')) };
}

function openStatus(hours: Business['openingHours'], timezone: string): { open: boolean; text: string } | null {
    if (!hours || Object.keys(hours).length === 0) return null;
    const { day, minutes } = nowIn(timezone);
    const today = hours[day] ?? [];
    for (const range of today) {
        const start = toMinutes(range.open);
        let end = toMinutes(range.close);
        if (end <= start) end += 24 * 60; // closes after midnight
        if (minutes >= start && minutes < end) {
            return { open: true, text: `Open now · closes ${formatTime(range.close)}` };
        }
    }
    const later = today.find((r) => toMinutes(r.open) > minutes);
    if (later) return { open: false, text: `Closed · opens ${formatTime(later.open)}` };
    return { open: false, text: 'Closed now' };
}

function whatsappLink(number: string): string {
    return `https://wa.me/${number.replace(/[^\d]/g, '')}`;
}

function directionsLink(b: Business): string | null {
    if (b.latitude != null && b.longitude != null) {
        return `https://www.google.com/maps/dir/?api=1&destination=${b.latitude},${b.longitude}`;
    }
    if (b.address) return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(b.address)}`;
    return null;
}

function withScheme(url: string): string {
    return /^https?:\/\//i.test(url) ? url : `https://${url}`;
}

function displayUrl(url: string): string {
    return url.replace(/^https?:\/\//i, '').replace(/\/$/, '');
}

/* ── Icons (inline, stroke uses currentColor) ─────────────────────────────── */
const icon = (d: React.ReactNode) =>
    function Icon({ className = 'h-5 w-5' }: { className?: string }) {
        return (
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
                {d}
            </svg>
        );
    };
const PhoneIcon = icon(<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z" />);
const ChatIcon = icon(<path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5z" />);
const PinIcon = icon(
    <>
        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" />
        <circle cx="12" cy="10" r="3" />
    </>,
);
const MailIcon = icon(
    <>
        <rect x="2" y="4" width="20" height="16" rx="2" />
        <path d="m22 7-10 6L2 7" />
    </>,
);
const ClockIcon = icon(
    <>
        <circle cx="12" cy="12" r="10" />
        <path d="M12 6v6l4 2" />
    </>,
);
const GlobeIcon = icon(
    <>
        <circle cx="12" cy="12" r="10" />
        <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
    </>,
);
const InfoIcon = icon(
    <>
        <circle cx="12" cy="12" r="10" />
        <path d="M12 16v-4M12 8h.01" />
    </>,
);
const StoreIcon = icon(<path d="M3 9l1.5-5h15L21 9M3 9v11h18V9M3 9h18M9 20v-6h6v6" />);
const ShareIcon = icon(<path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8M16 6l-4-4-4 4M12 2v13" />);

/* ── Building blocks ─────────────────────────────────────────────────────── */
function Card({ title, iconEl, children }: { title: string; iconEl: React.ReactNode; children: React.ReactNode }) {
    return (
        <section className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-white/10">
            <h2 className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                <span className="text-[var(--brand)]">{iconEl}</span>
                {title}
            </h2>
            {children}
        </section>
    );
}

function ActionButton({ href, label, children, external }: { href: string; label: string; children: React.ReactNode; external?: boolean }) {
    return (
        <a
            href={href}
            {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            className="flex flex-col items-center gap-1.5 rounded-2xl bg-white px-2 py-3 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-slate-200/70 transition active:scale-95 dark:bg-slate-900 dark:text-slate-200 dark:ring-white/10"
        >
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-[var(--brand-soft)] text-[var(--brand)]">{children}</span>
            {label}
        </a>
    );
}

export default function BusinessShow({ business: b, branches, pageUrl }: Props) {
    const brand = safeColor(b.primaryColor);
    const [status, setStatus] = useState(() => openStatus(b.openingHours, b.timezone));
    const [shared, setShared] = useState(false);

    // The status badge is time-dependent: refresh it every minute.
    useEffect(() => {
        const id = setInterval(() => setStatus(openStatus(b.openingHours, b.timezone)), 60_000);
        return () => clearInterval(id);
    }, [b.openingHours, b.timezone]);

    const today = useMemo(() => nowIn(b.timezone).day, [b.timezone]);
    const directions = directionsLink(b);
    const hasHours = b.openingHours && Object.keys(b.openingHours).length > 0;
    const hasOnline = b.website || b.facebookUrl || b.instagramUrl;

    const share = async () => {
        try {
            if (navigator.share) {
                await navigator.share({ title: b.name, url: pageUrl });
            } else {
                await navigator.clipboard.writeText(pageUrl);
                setShared(true);
                setTimeout(() => setShared(false), 2000);
            }
        } catch {
            /* user cancelled */
        }
    };

    const initials = b.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');

    return (
        <div
            className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100"
            style={{ ['--brand' as string]: brand, ['--brand-soft' as string]: `${brand}1f` }}
        >
            <Head title={b.name}>
                <meta name="description" content={b.tagline ?? `Visit ${b.name}`} />
                <meta property="og:title" content={b.name} />
                {b.tagline && <meta property="og:description" content={b.tagline} />}
                {b.logoUrl && <meta property="og:image" content={b.logoUrl} />}
                <meta name="theme-color" content={brand} />
            </Head>

            {/* Hero */}
            <header className="relative overflow-hidden px-4 pb-16 pt-10 text-center text-white" style={{ background: `linear-gradient(160deg, ${brand}, ${brand}cc 60%, #0f172a)` }}>
                <div aria-hidden="true" className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl" />
                <div aria-hidden="true" className="pointer-events-none absolute -bottom-20 -left-10 h-56 w-56 rounded-full bg-black/10 blur-2xl" />

                <div className="relative mx-auto max-w-md">
                    <div className="mx-auto flex h-24 w-24 items-center justify-center overflow-hidden rounded-3xl bg-white shadow-lg ring-4 ring-white/30">
                        {b.logoUrl ? (
                            <img src={b.logoUrl} alt={`${b.name} logo`} className="h-full w-full object-contain p-2" />
                        ) : (
                            <span className="text-3xl font-bold" style={{ color: brand }}>
                                {initials}
                            </span>
                        )}
                    </div>
                    <h1 className="mt-5 text-2xl font-bold leading-tight tracking-tight">{b.name}</h1>
                    {b.tagline && <p className="mt-2 text-base italic text-white/85">&ldquo;{b.tagline}&rdquo;</p>}
                    {status && (
                        <span
                            className={`mt-4 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1 ${
                                status.open ? 'bg-emerald-400/20 text-emerald-50 ring-emerald-300/40' : 'bg-white/10 text-white/80 ring-white/20'
                            }`}
                        >
                            <span className={`h-2 w-2 rounded-full ${status.open ? 'animate-pulse bg-emerald-300' : 'bg-white/50'}`} />
                            {status.text}
                        </span>
                    )}
                </div>
            </header>

            <main className="relative mx-auto -mt-10 max-w-md space-y-4 px-4 pb-10">
                {/* Quick actions */}
                <nav className="grid grid-cols-4 gap-2" aria-label="Contact">
                    {b.phone && (
                        <ActionButton href={`tel:${b.phone.replace(/\s+/g, '')}`} label="Call">
                            <PhoneIcon />
                        </ActionButton>
                    )}
                    {b.whatsapp && (
                        <ActionButton href={whatsappLink(b.whatsapp)} label="WhatsApp" external>
                            <ChatIcon />
                        </ActionButton>
                    )}
                    {directions && (
                        <ActionButton href={directions} label="Directions" external>
                            <PinIcon />
                        </ActionButton>
                    )}
                    {b.email && (
                        <ActionButton href={`mailto:${b.email}`} label="Email">
                            <MailIcon />
                        </ActionButton>
                    )}
                </nav>

                {b.about && (
                    <Card title="About us" iconEl={<InfoIcon className="h-4 w-4" />}>
                        <p className="whitespace-pre-line text-[15px] leading-relaxed text-slate-700 dark:text-slate-300">{b.about}</p>
                    </Card>
                )}

                {hasHours && (
                    <Card title="Opening hours" iconEl={<ClockIcon className="h-4 w-4" />}>
                        <ul className="divide-y divide-slate-100 text-sm dark:divide-white/5">
                            {DAYS.map(({ key, label }) => {
                                const ranges = b.openingHours?.[key] ?? [];
                                const isToday = key === today;
                                return (
                                    <li key={key} className={`flex justify-between py-2 ${isToday ? 'font-semibold text-[var(--brand)]' : 'text-slate-600 dark:text-slate-300'}`}>
                                        <span>
                                            {label}
                                            {isToday && <span className="ml-2 text-[10px] uppercase tracking-wide opacity-80">Today</span>}
                                        </span>
                                        <span className="tabular-nums">
                                            {ranges.length === 0 ? <span className="text-slate-400">Closed</span> : ranges.map((r) => `${formatTime(r.open)} – ${formatTime(r.close)}`).join(', ')}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    </Card>
                )}

                {(b.address || directions) && (
                    <Card title="Visit us" iconEl={<PinIcon className="h-4 w-4" />}>
                        {b.address && <p className="whitespace-pre-line text-[15px] leading-relaxed text-slate-700 dark:text-slate-300">{b.address}</p>}
                        {directions && (
                            <a
                                href={directions}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl py-3 text-sm font-semibold text-white shadow-sm transition active:scale-[0.98]"
                                style={{ backgroundColor: brand }}
                            >
                                <PinIcon className="h-4 w-4" /> Get directions
                            </a>
                        )}
                    </Card>
                )}

                {branches.length > 0 && (
                    <Card title="Our branches" iconEl={<StoreIcon className="h-4 w-4" />}>
                        <ul className="space-y-3">
                            {branches.map((branch) => (
                                <li key={branch.name} className="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                                    <p className="font-semibold">{branch.name}</p>
                                    {branch.address && <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{branch.address}</p>}
                                    {branch.phone && (
                                        <a href={`tel:${branch.phone.replace(/\s+/g, '')}`} className="mt-1 inline-block text-sm font-medium text-[var(--brand)]">
                                            {branch.phone}
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {(b.phone || b.email || hasOnline) && (
                    <Card title="Get in touch" iconEl={<GlobeIcon className="h-4 w-4" />}>
                        <ul className="space-y-2.5 text-[15px]">
                            {b.phone && (
                                <li>
                                    <a href={`tel:${b.phone.replace(/\s+/g, '')}`} className="flex items-center gap-3 text-slate-700 dark:text-slate-300">
                                        <PhoneIcon className="h-4 w-4 text-slate-400" /> {b.phone}
                                    </a>
                                </li>
                            )}
                            {b.email && (
                                <li>
                                    <a href={`mailto:${b.email}`} className="flex items-center gap-3 break-all text-slate-700 dark:text-slate-300">
                                        <MailIcon className="h-4 w-4 shrink-0 text-slate-400" /> {b.email}
                                    </a>
                                </li>
                            )}
                            {b.website && (
                                <li>
                                    <a href={withScheme(b.website)} target="_blank" rel="noopener noreferrer" className="flex items-center gap-3 break-all text-slate-700 dark:text-slate-300">
                                        <GlobeIcon className="h-4 w-4 shrink-0 text-slate-400" /> {displayUrl(b.website)}
                                    </a>
                                </li>
                            )}
                        </ul>
                        {(b.facebookUrl || b.instagramUrl) && (
                            <div className="mt-4 flex gap-2">
                                {b.facebookUrl && (
                                    <a
                                        href={withScheme(b.facebookUrl)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex-1 rounded-xl bg-[#1877f2] py-2.5 text-center text-sm font-semibold text-white"
                                    >
                                        Facebook
                                    </a>
                                )}
                                {b.instagramUrl && (
                                    <a
                                        href={withScheme(b.instagramUrl)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex-1 rounded-xl bg-gradient-to-r from-[#f58529] via-[#dd2a7b] to-[#8134af] py-2.5 text-center text-sm font-semibold text-white"
                                    >
                                        Instagram
                                    </a>
                                )}
                            </div>
                        )}
                    </Card>
                )}

                <button
                    type="button"
                    onClick={share}
                    className="flex w-full items-center justify-center gap-2 rounded-2xl py-3 text-sm font-medium text-slate-600 ring-1 ring-slate-200 transition active:scale-[0.98] dark:text-slate-300 dark:ring-white/10"
                >
                    <ShareIcon className="h-4 w-4" /> {shared ? 'Link copied' : `Share ${b.name}`}
                </button>

                <p className="pt-2 text-center text-xs text-slate-400">Thank you for shopping with us · Powered by SmartPOS</p>
            </main>
        </div>
    );
}
