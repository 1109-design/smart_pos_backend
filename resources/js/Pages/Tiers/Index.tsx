import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

interface Tier {
    key: string;
    label: string;
    price: string;
    features: string[];
    sort_order: number;
}

interface Props {
    tiers: Tier[];
}

const emptyForm = { key: '', label: '', price: '', features: [''], sort_order: 0 };

// Cycle through accent colours so each card looks distinct
const accentColors = [
    { bg: 'bg-indigo-600', light: 'bg-indigo-50', text: 'text-indigo-600', border: 'border-indigo-200' },
    { bg: 'bg-violet-600', light: 'bg-violet-50', text: 'text-violet-600', border: 'border-violet-200' },
    { bg: 'bg-purple-600', light: 'bg-purple-50', text: 'text-purple-600', border: 'border-purple-200' },
    { bg: 'bg-fuchsia-600', light: 'bg-fuchsia-50', text: 'text-fuchsia-600', border: 'border-fuchsia-200' },
];

function getAccent(index: number) {
    return accentColors[index % accentColors.length];
}

// ── Shared input style ──────────────────────────────────────────
const inputCls = 'form-input';

export default function TiersIndex({ tiers }: Props) {
    const [showCreate, setShowCreate] = useState(false);
    const [editingKey, setEditingKey] = useState<string | null>(null);

    // ── Create form ──────────────────────────────────────────────
    const create = useForm({ ...emptyForm });

    function addCreateFeature() {
        create.setData('features', [...create.data.features, '']);
    }
    function removeCreateFeature(i: number) {
        create.setData('features', create.data.features.filter((_, idx) => idx !== i));
    }
    function setCreateFeature(i: number, val: string) {
        const next = [...create.data.features];
        next[i] = val;
        create.setData('features', next);
    }
    function submitCreate(e: React.FormEvent) {
        e.preventDefault();
        create.post('/tiers', {
            onSuccess: () => { create.reset(); setShowCreate(false); },
        });
    }

    // ── Edit form ────────────────────────────────────────────────
    const edit = useForm({ label: '', price: '', features: [''], sort_order: 0 });

    function startEdit(tier: Tier) {
        edit.setData({ label: tier.label, price: tier.price, features: [...tier.features], sort_order: tier.sort_order });
        setEditingKey(tier.key);
    }
    function addEditFeature() {
        edit.setData('features', [...edit.data.features, '']);
    }
    function removeEditFeature(i: number) {
        edit.setData('features', edit.data.features.filter((_, idx) => idx !== i));
    }
    function setEditFeature(i: number, val: string) {
        const next = [...edit.data.features];
        next[i] = val;
        edit.setData('features', next);
    }
    function submitEdit(e: React.FormEvent) {
        e.preventDefault();
        edit.put(`/tiers/${editingKey}`, {
            onSuccess: () => setEditingKey(null),
        });
    }

    function deleteTier(key: string, label: string) {
        if (confirm(`Delete the "${label}" tier? Businesses currently on this tier will keep their tier key but may break.`)) {
            router.delete(`/tiers/${key}`);
        }
    }

    return (
        <AppLayout>
            <Head title="Tiers" />

            {/* Header */}
            <div className="mb-8 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Subscription Tiers</h1>
                    <p className="text-sm text-slate-500 mt-1">Manage available plans for SmartPOS businesses</p>
                </div>
                <button
                    onClick={() => { setShowCreate(!showCreate); setEditingKey(null); }}
                    className="btn-primary"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                    New Tier
                </button>
            </div>

            {/* ── Create form ──────────────────────────────────────── */}
            {showCreate && (
                <div className="bg-white rounded-2xl border border-indigo-100 shadow-sm p-6 mb-8">
                    <div className="flex items-center gap-2 mb-5">
                        <span className="w-1 h-5 rounded-full bg-indigo-600 inline-block" />
                        <h2 className="font-semibold text-gray-800">Create New Tier</h2>
                    </div>
                    <form onSubmit={submitCreate} className="space-y-5">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label className="block text-xs font-medium text-gray-500 mb-1">Key <span className="text-gray-400">(e.g. pro)</span></label>
                                <input value={create.data.key} onChange={e => create.setData('key', e.target.value)} placeholder="pro" className={inputCls} required />
                                {create.errors.key && <p className="text-red-500 text-xs mt-1">{create.errors.key}</p>}
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-gray-500 mb-1">Label</label>
                                <input value={create.data.label} onChange={e => create.setData('label', e.target.value)} placeholder="Pro" className={inputCls} required />
                                {create.errors.label && <p className="text-red-500 text-xs mt-1">{create.errors.label}</p>}
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-gray-500 mb-1">Price</label>
                                <input value={create.data.price} onChange={e => create.setData('price', e.target.value)} placeholder="$9/month" className={inputCls} required />
                                {create.errors.price && <p className="text-red-500 text-xs mt-1">{create.errors.price}</p>}
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-gray-500 mb-1">Sort Order</label>
                                <input type="number" min={0} value={create.data.sort_order} onChange={e => create.setData('sort_order', Number(e.target.value))} className={inputCls} />
                            </div>
                        </div>

                        <div>
                            <label className="block text-xs font-medium text-gray-500 mb-2">Features</label>
                            <div className="space-y-2">
                                {create.data.features.map((f, i) => (
                                    <div key={i} className="flex gap-2">
                                        <input value={f} onChange={e => setCreateFeature(i, e.target.value)} placeholder={`Feature ${i + 1}`} className={inputCls} />
                                        {create.data.features.length > 1 && (
                                            <button type="button" onClick={() => removeCreateFeature(i)} className="text-red-400 hover:text-red-600 px-2 text-lg leading-none">×</button>
                                        )}
                                    </div>
                                ))}
                            </div>
                            <button type="button" onClick={addCreateFeature} className="mt-2 text-xs text-indigo-600 hover:text-indigo-800 font-medium">+ Add feature</button>
                        </div>

                        <div className="flex justify-end gap-3 pt-1 border-t border-gray-100">
                            <button type="button" onClick={() => setShowCreate(false)} className="text-sm text-gray-600 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                            <button type="submit" disabled={create.processing} className="text-sm font-medium text-white bg-indigo-600 px-5 py-2 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                Create Tier
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* ── Tier cards grid ──────────────────────────────────── */}
            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                {tiers.map((tier, idx) => {
                    const accent = getAccent(idx);

                    if (editingKey === tier.key) {
                        // ── Inline edit card ──
                        return (
                            <div key={tier.key} className="bg-white rounded-2xl border-2 border-indigo-300 shadow-sm col-span-1">
                                <div className="bg-indigo-50 px-5 py-4 rounded-t-2xl border-b border-indigo-100 flex items-center gap-2">
                                    <span className="font-semibold text-indigo-700">Editing</span>
                                    <span className="font-mono text-xs bg-indigo-100 text-indigo-600 px-2 py-0.5 rounded-full">{tier.key}</span>
                                </div>
                                <div className="p-5">
                                    <form onSubmit={submitEdit} className="space-y-4">
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="col-span-2">
                                                <label className="block text-xs font-medium text-gray-500 mb-1">Label</label>
                                                <input value={edit.data.label} onChange={e => edit.setData('label', e.target.value)} className={inputCls} required />
                                                {edit.errors.label && <p className="text-red-500 text-xs mt-1">{edit.errors.label}</p>}
                                            </div>
                                            <div>
                                                <label className="block text-xs font-medium text-gray-500 mb-1">Price</label>
                                                <input value={edit.data.price} onChange={e => edit.setData('price', e.target.value)} className={inputCls} required />
                                                {edit.errors.price && <p className="text-red-500 text-xs mt-1">{edit.errors.price}</p>}
                                            </div>
                                            <div>
                                                <label className="block text-xs font-medium text-gray-500 mb-1">Sort Order</label>
                                                <input type="number" min={0} value={edit.data.sort_order} onChange={e => edit.setData('sort_order', Number(e.target.value))} className={inputCls} />
                                            </div>
                                        </div>

                                        <div>
                                            <label className="block text-xs font-medium text-gray-500 mb-2">Features</label>
                                            <div className="space-y-1.5">
                                                {edit.data.features.map((f, i) => (
                                                    <div key={i} className="flex gap-2">
                                                        <input value={f} onChange={e => setEditFeature(i, e.target.value)} className={inputCls} />
                                                        {edit.data.features.length > 1 && (
                                                            <button type="button" onClick={() => removeEditFeature(i)} className="text-red-400 hover:text-red-600 px-2 text-lg leading-none">×</button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                            <button type="button" onClick={addEditFeature} className="mt-2 text-xs text-indigo-600 hover:text-indigo-800 font-medium">+ Add feature</button>
                                        </div>

                                        <div className="flex gap-2 pt-2 border-t border-gray-100">
                                            <button type="button" onClick={() => setEditingKey(null)} className="flex-1 text-sm text-gray-600 px-3 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                                            <button type="submit" disabled={edit.processing} className="flex-1 text-sm font-medium text-white bg-indigo-600 px-3 py-2 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        );
                    }

                    // ── View card ──
                    return (
                        <div key={tier.key} className="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col">
                            {/* Coloured header band */}
                            <div className={`${accent.bg} px-6 pt-6 pb-5`}>
                                <div className="flex items-start justify-between mb-3">
                                    <span className="inline-flex items-center gap-1.5 bg-white/20 text-white text-xs font-medium px-2.5 py-1 rounded-full">
                                        #{tier.sort_order} &nbsp;·&nbsp; <span className="font-mono">{tier.key}</span>
                                    </span>
                                </div>
                                <p className="text-white text-xl font-bold leading-tight">{tier.label}</p>
                                <p className="text-white/80 text-sm mt-0.5">{tier.price}</p>
                            </div>

                            {/* Features */}
                            <div className="flex-1 px-6 py-5">
                                <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">What's included</p>
                                <ul className="space-y-2">
                                    {tier.features.map(f => (
                                        <li key={f} className="flex items-start gap-2 text-sm text-gray-700">
                                            <svg className="w-4 h-4 text-green-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                            {f}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {/* Actions */}
                            <div className="px-6 py-4 border-t border-gray-100 flex gap-2">
                                <button
                                    onClick={() => { startEdit(tier); setShowCreate(false); }}
                                    className={`flex-1 text-sm font-medium px-3 py-2 rounded-lg border ${accent.border} ${accent.light} ${accent.text} hover:opacity-80 transition-opacity`}
                                >
                                    Edit
                                </button>
                                <button
                                    onClick={() => deleteTier(tier.key, tier.label)}
                                    className="flex-1 text-sm font-medium px-3 py-2 rounded-lg border border-red-100 bg-red-50 text-red-500 hover:bg-red-100 transition-colors"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    );
                })}

                {tiers.length === 0 && (
                    <div className="col-span-3 bg-white rounded-2xl border border-gray-200 px-5 py-16 text-center text-gray-400">
                        No tiers yet. Click <span className="font-medium text-indigo-600">+ New Tier</span> to create one.
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
