import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

interface AssetRow {
    id: string;
    asset_number: string | null;
    name: string;
    category: string | null;
    status: 'active' | 'disposed';
    acquisition_date: string;
    acquisition_cost: number;
    accumulated_depreciation: number;
    book_value: number;
}

interface Props {
    assets: AssetRow[];
}

const fmt = (n: number) => n.toFixed(2);
const today = () => new Date().toISOString().slice(0, 10);

export default function BackOfficeAssets({ assets }: Props) {
    const { flash, errors } = usePage().props as unknown as {
        flash: { success: string | null };
        errors: Record<string, string>;
    };

    const [showNew, setShowNew] = useState(false);
    const [name, setName] = useState('');
    const [assetNumber, setAssetNumber] = useState('');
    const [category, setCategory] = useState('');
    const [acquisitionDate, setAcquisitionDate] = useState(today());
    const [acquisitionCost, setAcquisitionCost] = useState('');
    const [salvageValue, setSalvageValue] = useState('');
    const [usefulLifeMonths, setUsefulLifeMonths] = useState('');
    const [fundingMethod, setFundingMethod] = useState<'cash' | 'bank'>('cash');

    const [disposing, setDisposing] = useState<AssetRow | null>(null);
    const [disposedAt, setDisposedAt] = useState(today());
    const [disposalProceeds, setDisposalProceeds] = useState('');

    const resetForm = () => {
        setName('');
        setAssetNumber('');
        setCategory('');
        setAcquisitionDate(today());
        setAcquisitionCost('');
        setSalvageValue('');
        setUsefulLifeMonths('');
        setFundingMethod('cash');
    };

    const submitNew = () => {
        router.post(
            '/office/assets',
            {
                name,
                asset_number: assetNumber || null,
                category: category || null,
                acquisition_date: acquisitionDate,
                acquisition_cost: acquisitionCost,
                salvage_value: salvageValue || 0,
                useful_life_months: usefulLifeMonths,
                funding_method: fundingMethod,
            },
            { preserveScroll: true, onSuccess: () => { setShowNew(false); resetForm(); } }
        );
    };

    const openDispose = (asset: AssetRow) => {
        setDisposing(asset);
        setDisposedAt(today());
        setDisposalProceeds('');
    };

    const submitDispose = () => {
        if (!disposing) return;
        router.post(
            `/office/assets/${disposing.id}/dispose`,
            { disposed_at: disposedAt, disposal_proceeds: disposalProceeds || 0 },
            { preserveScroll: true, onSuccess: () => setDisposing(null) }
        );
    };

    return (
        <BackOfficeLayout>
            <Head title="Assets" />

            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Assets</h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Fixed assets, depreciated monthly on a diminishing (reducing) balance. Acquiring or
                        disposing of one posts straight to the general ledger.
                    </p>
                </div>
                <button onClick={() => setShowNew(true)} className="btn-primary py-2">
                    + New Asset
                </button>
            </div>

            {flash?.success && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}
            {errors?.disposal_proceeds && (
                <div className="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {errors.disposal_proceeds}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[780px]">
                        <thead>
                            <tr className="bg-slate-50 text-left text-xs font-semibold text-slate-400 uppercase tracking-wide">
                                <th className="px-5 py-3">Asset</th>
                                <th className="px-5 py-3">Acquired</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3 text-right">Cost</th>
                                <th className="px-5 py-3 text-right">Accum. Depreciation</th>
                                <th className="px-5 py-3 text-right">Book Value</th>
                                <th className="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {assets.map((a) => (
                                <tr key={a.id} className="hover:bg-slate-50/60">
                                    <td className="px-5 py-3">
                                        <p className="font-medium text-slate-900">{a.name}</p>
                                        <p className="text-xs text-slate-400">
                                            {[a.asset_number, a.category].filter(Boolean).join(' · ') || '—'}
                                        </p>
                                    </td>
                                    <td className="px-5 py-3 text-slate-600 whitespace-nowrap">
                                        {new Date(a.acquisition_date).toLocaleDateString()}
                                    </td>
                                    <td className="px-5 py-3">
                                        <StatusBadge label={a.status === 'active' ? 'Active' : 'Disposed'} variant={a.status === 'active' ? 'green' : 'gray'} />
                                    </td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-700">{fmt(a.acquisition_cost)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums text-slate-700">{fmt(a.accumulated_depreciation)}</td>
                                    <td className="px-5 py-3 text-right tabular-nums font-semibold text-slate-900">{fmt(a.book_value)}</td>
                                    <td className="px-5 py-3 text-right">
                                        {a.status === 'active' && (
                                            <button onClick={() => openDispose(a)} className="text-xs font-semibold text-red-600 hover:text-red-800">
                                                Dispose
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {assets.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-5 py-10 text-center text-sm text-slate-400">No assets registered yet.</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal show={showNew} onClose={() => setShowNew(false)} maxWidth="lg">
                <div className="p-6">
                    <h2 className="text-lg font-semibold text-slate-900 mb-4">New Asset</h2>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="col-span-2">
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Name</label>
                            <input value={name} onChange={(e) => setName(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Asset number (optional)</label>
                            <input value={assetNumber} onChange={(e) => setAssetNumber(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Category (optional)</label>
                            <input value={category} onChange={(e) => setCategory(e.target.value)} placeholder="e.g. Vehicles" className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Acquisition date</label>
                            <input type="date" value={acquisitionDate} onChange={(e) => setAcquisitionDate(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Paid from</label>
                            <select value={fundingMethod} onChange={(e) => setFundingMethod(e.target.value as 'cash' | 'bank')} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Acquisition cost</label>
                            <input type="number" step="0.01" min="0.01" value={acquisitionCost} onChange={(e) => setAcquisitionCost(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Salvage value (optional)</label>
                            <input type="number" step="0.01" min="0" value={salvageValue} onChange={(e) => setSalvageValue(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                        <div className="col-span-2">
                            <label className="block text-xs font-semibold text-slate-500 mb-1">Useful life (months)</label>
                            <input type="number" step="1" min="1" value={usefulLifeMonths} onChange={(e) => setUsefulLifeMonths(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 mt-6">
                        <button onClick={() => setShowNew(false)} className="text-sm text-slate-500 px-4 py-2">Cancel</button>
                        <button
                            onClick={submitNew}
                            disabled={!name || !acquisitionCost || !usefulLifeMonths}
                            className="btn-primary py-2 px-5 disabled:opacity-50"
                        >
                            Create
                        </button>
                    </div>
                </div>
            </Modal>

            <Modal show={!!disposing} onClose={() => setDisposing(null)} maxWidth="md">
                {disposing && (
                    <div className="p-6">
                        <h2 className="text-lg font-semibold text-slate-900 mb-1">Dispose of {disposing.name}</h2>
                        <p className="text-xs text-slate-500 mb-4">
                            Book value right now: {fmt(disposing.book_value)}. Any difference from what it's sold or scrapped
                            for posts as a gain or loss.
                        </p>
                        <div className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 mb-1">Disposal date</label>
                                <input type="date" value={disposedAt} onChange={(e) => setDisposedAt(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-slate-500 mb-1">Proceeds (0 if scrapped)</label>
                                <input type="number" step="0.01" min="0" value={disposalProceeds} onChange={(e) => setDisposalProceeds(e.target.value)} className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2" />
                            </div>
                        </div>
                        <div className="flex justify-end gap-2 mt-6">
                            <button onClick={() => setDisposing(null)} className="text-sm text-slate-500 px-4 py-2">Cancel</button>
                            <button onClick={submitDispose} className="btn-primary py-2 px-5">Confirm Disposal</button>
                        </div>
                    </div>
                )}
            </Modal>
        </BackOfficeLayout>
    );
}
