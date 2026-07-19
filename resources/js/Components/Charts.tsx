import React, { useState } from 'react';

/**
 * Lightweight SVG charts for the BackOffice — no charting library.
 * Single-series, brand emerald (#059669 — validated ≥3:1 on white),
 * recessive grid, direct labels, per-mark hover tooltips.
 */

const MARK = '#059669';
const MARK_SOFT = '#a7f3d0';
const GRID = '#f1f5f9';
const INK_MUTED = '#94a3b8';

function niceMax(value: number): number {
    if (value <= 0) return 1;
    const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
    const normalized = value / magnitude;
    const nice = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
    return nice * magnitude;
}

function compactNumber(value: number): string {
    if (Math.abs(value) >= 1_000_000) return `${(value / 1_000_000).toFixed(1)}M`;
    if (Math.abs(value) >= 1_000) return `${(value / 1_000).toFixed(1)}k`;
    return value.toFixed(0);
}

// ─── Column trend (revenue per day) ──────────────────────────────────────────

export interface TrendPoint {
    label: string;      // x-axis label, e.g. "Mon 14"
    tooltip: string;    // full label for the tooltip, e.g. "Mon 14 Jul"
    value: number;
    secondary?: string; // extra tooltip line, e.g. "12 transactions"
}

export function ColumnTrendChart({ points, valueFormatter }: {
    points: TrendPoint[];
    valueFormatter: (value: number) => string;
}) {
    const [hover, setHover] = useState<number | null>(null);

    const width = 720;
    const height = 220;
    const pad = { top: 12, right: 8, bottom: 26, left: 44 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    const max = niceMax(Math.max(...points.map((p) => p.value), 0));
    const n = points.length;
    const step = plotW / Math.max(n, 1);
    const barW = Math.max(Math.min(step * 0.7, 40), 3);

    const gridLines = [0.25, 0.5, 0.75, 1];
    // Label roughly every ~6th column so labels never collide.
    const labelEvery = Math.max(1, Math.ceil(n / 6));

    return (
        <div className="relative">
            <svg viewBox={`0 0 ${width} ${height}`} className="w-full h-auto" role="img" aria-label="Revenue per day">
                {gridLines.map((g) => (
                    <g key={g}>
                        <line
                            x1={pad.left} x2={width - pad.right}
                            y1={pad.top + plotH * (1 - g)} y2={pad.top + plotH * (1 - g)}
                            stroke={GRID} strokeWidth={1}
                        />
                        <text
                            x={pad.left - 6} y={pad.top + plotH * (1 - g) + 3}
                            textAnchor="end" fontSize={10} fill={INK_MUTED}
                        >
                            {compactNumber(max * g)}
                        </text>
                    </g>
                ))}
                <line x1={pad.left} x2={width - pad.right} y1={pad.top + plotH} y2={pad.top + plotH} stroke={GRID} strokeWidth={1} />

                {points.map((p, i) => {
                    const h = max > 0 ? (p.value / max) * plotH : 0;
                    const x = pad.left + i * step + (step - barW) / 2;
                    const y = pad.top + plotH - h;
                    const dimmed = hover !== null && hover !== i;
                    return (
                        <g key={i}>
                            {/* Rounded top, flat baseline: clip a rounded rect at the baseline */}
                            <path
                                d={`M ${x} ${pad.top + plotH}
                                    L ${x} ${y + 4}
                                    Q ${x} ${y} ${x + 4} ${y}
                                    L ${x + barW - 4} ${y}
                                    Q ${x + barW} ${y} ${x + barW} ${y + 4}
                                    L ${x + barW} ${pad.top + plotH} Z`}
                                fill={dimmed ? MARK_SOFT : MARK}
                            />
                            {/* Oversized invisible hit target */}
                            <rect
                                x={pad.left + i * step} y={pad.top} width={step} height={plotH}
                                fill="transparent"
                                onMouseEnter={() => setHover(i)}
                                onMouseLeave={() => setHover(null)}
                            />
                            {i % labelEvery === 0 && (
                                <text
                                    x={x + barW / 2} y={height - 8}
                                    textAnchor="middle" fontSize={10} fill={INK_MUTED}
                                >
                                    {p.label}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>

            {hover !== null && points[hover] && (
                <div
                    className="pointer-events-none absolute z-10 rounded-lg bg-slate-800 px-3 py-2 text-xs text-white shadow-lg"
                    style={{
                        left: `${((pad.left + hover * step + step / 2) / width) * 100}%`,
                        top: 0,
                        transform: 'translateX(-50%)',
                    }}
                >
                    <p className="font-semibold">{points[hover].tooltip}</p>
                    <p>{valueFormatter(points[hover].value)}</p>
                    {points[hover].secondary && <p className="text-slate-300">{points[hover].secondary}</p>}
                </div>
            )}
        </div>
    );
}

// ─── Line trend (same data as columns, drawn as a line + area) ───────────────

export function LineTrendChart({ points, valueFormatter }: {
    points: TrendPoint[];
    valueFormatter: (value: number) => string;
}) {
    const [hover, setHover] = useState<number | null>(null);

    const width = 720;
    const height = 220;
    const pad = { top: 12, right: 12, bottom: 26, left: 44 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    const max = niceMax(Math.max(...points.map((p) => p.value), 0));
    const n = points.length;
    const xAt = (i: number) => (n <= 1 ? pad.left + plotW / 2 : pad.left + (i / (n - 1)) * plotW);
    const yAt = (v: number) => pad.top + plotH - (max > 0 ? (v / max) * plotH : 0);

    const linePath = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${xAt(i)} ${yAt(p.value)}`).join(' ');
    const areaPath = n > 0
        ? `${linePath} L ${xAt(n - 1)} ${pad.top + plotH} L ${xAt(0)} ${pad.top + plotH} Z`
        : '';

    const gridLines = [0.25, 0.5, 0.75, 1];
    const labelEvery = Math.max(1, Math.ceil(n / 6));

    const handleMove = (e: React.MouseEvent<SVGRectElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();
        const fraction = (e.clientX - rect.left) / rect.width;
        const i = Math.round(fraction * (n - 1));
        setHover(Math.min(Math.max(i, 0), n - 1));
    };

    return (
        <div className="relative">
            <svg viewBox={`0 0 ${width} ${height}`} className="w-full h-auto" role="img" aria-label="Revenue trend">
                {gridLines.map((g) => (
                    <g key={g}>
                        <line
                            x1={pad.left} x2={width - pad.right}
                            y1={pad.top + plotH * (1 - g)} y2={pad.top + plotH * (1 - g)}
                            stroke={GRID} strokeWidth={1}
                        />
                        <text
                            x={pad.left - 6} y={pad.top + plotH * (1 - g) + 3}
                            textAnchor="end" fontSize={10} fill={INK_MUTED}
                        >
                            {compactNumber(max * g)}
                        </text>
                    </g>
                ))}
                <line x1={pad.left} x2={width - pad.right} y1={pad.top + plotH} y2={pad.top + plotH} stroke={GRID} strokeWidth={1} />

                {areaPath && <path d={areaPath} fill={MARK} opacity={0.08} />}
                {linePath && <path d={linePath} fill="none" stroke={MARK} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />}

                {/* Crosshair + emphasized marker on hover */}
                {hover !== null && points[hover] && (
                    <g>
                        <line
                            x1={xAt(hover)} x2={xAt(hover)}
                            y1={pad.top} y2={pad.top + plotH}
                            stroke={INK_MUTED} strokeWidth={1} strokeDasharray="3 3"
                        />
                        <circle cx={xAt(hover)} cy={yAt(points[hover].value)} r={4.5} fill={MARK} stroke="#ffffff" strokeWidth={2} />
                    </g>
                )}

                {points.map((p, i) => (
                    i % labelEvery === 0 ? (
                        <text key={i} x={xAt(i)} y={height - 8} textAnchor="middle" fontSize={10} fill={INK_MUTED}>
                            {p.label}
                        </text>
                    ) : null
                ))}

                <rect
                    x={pad.left} y={pad.top} width={plotW} height={plotH}
                    fill="transparent"
                    onMouseMove={handleMove}
                    onMouseLeave={() => setHover(null)}
                />
            </svg>

            {hover !== null && points[hover] && (
                <div
                    className="pointer-events-none absolute z-10 rounded-lg bg-slate-800 px-3 py-2 text-xs text-white shadow-lg"
                    style={{
                        left: `${(xAt(hover) / width) * 100}%`,
                        top: 0,
                        transform: 'translateX(-50%)',
                    }}
                >
                    <p className="font-semibold">{points[hover].tooltip}</p>
                    <p>{valueFormatter(points[hover].value)}</p>
                    {points[hover].secondary && <p className="text-slate-300">{points[hover].secondary}</p>}
                </div>
            )}
        </div>
    );
}

// ─── Ranked horizontal bars (top products, cashiers) ─────────────────────────

export interface RankedBar {
    label: string;
    value: number;
    secondary?: string; // e.g. "34 units" / "12 txns"
}

export function RankedBarChart({ bars, valueFormatter }: {
    bars: RankedBar[];
    valueFormatter: (value: number) => string;
}) {
    const [hover, setHover] = useState<number | null>(null);
    const max = Math.max(...bars.map((b) => b.value), 0);

    return (
        <div className="space-y-2.5">
            {bars.map((bar, i) => {
                const pct = max > 0 ? (bar.value / max) * 100 : 0;
                const dimmed = hover !== null && hover !== i;
                return (
                    <div
                        key={i}
                        className="group"
                        onMouseEnter={() => setHover(i)}
                        onMouseLeave={() => setHover(null)}
                    >
                        <div className="flex items-baseline justify-between gap-3 mb-1">
                            <p className="text-xs font-medium text-slate-600 truncate">{bar.label}</p>
                            <p className="text-xs font-semibold text-slate-800 tabular-nums flex-shrink-0">
                                {valueFormatter(bar.value)}
                                {bar.secondary && <span className="ml-1.5 font-normal text-slate-400">{bar.secondary}</span>}
                            </p>
                        </div>
                        <div className="h-2 w-full rounded-full bg-slate-100">
                            <div
                                className="h-2 rounded-full transition-colors"
                                style={{ width: `${pct}%`, minWidth: bar.value > 0 ? 4 : 0, backgroundColor: dimmed ? MARK_SOFT : MARK }}
                            />
                        </div>
                    </div>
                );
            })}
            {bars.length === 0 && <p className="text-sm text-slate-400">No data for this range.</p>}
        </div>
    );
}
