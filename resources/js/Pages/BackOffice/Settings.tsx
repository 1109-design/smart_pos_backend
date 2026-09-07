import React, { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout';
import Modal from '@/Components/Modal';

interface StockReset {
    done: boolean;
    at: string | null;
    by: string | null;
}

interface Workflows {
    stock_transfer_requires_approval: boolean;
    po_approval_threshold: number | null;
    stock_take_variance_threshold_percent: number | null;
}

interface Props {
    stock_reset: StockReset;
    catalogue_reset: StockReset;
    workflows: Workflows;
}

const CONFIRM_WORD = 'RESET';
const CATALOGUE_CONFIRM_PHRASE = 'DELETE EVERYTHING';

export default function BackOfficeSettings({ stock_reset, catalogue_reset, workflows }: Props) {
    const [showConfirm, setShowConfirm] = useState(false);
    const [showCatalogueConfirm, setShowCatalogueConfirm] = useState(false);
    const { flash } = usePage().props as unknown as { flash: { success: string | null } };
    const form = useForm({ confirm: '' });
    const catalogueForm = useForm({ confirm: '' });

    const [poThreshold, setPoThreshold] = useState(workflows.po_approval_threshold?.toString() ?? '');
    const [varianceThreshold, setVarianceThreshold] = useState(
        workflows.stock_take_variance_threshold_percent?.toString() ?? ''
    );

    const toggleWorkflow = (key: keyof Workflows, value: boolean) => {
        router.post(`/office/settings/workflows`, { [key]: value }, { preserveScroll: true });
    };

    const savePoThreshold = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            '/office/settings/workflows',
            { po_approval_threshold: poThreshold === '' ? null : poThreshold },
            { preserveScroll: true }
        );
    };

    const saveVarianceThreshold = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            '/office/settings/workflows',
            { stock_take_variance_threshold_percent: varianceThreshold === '' ? null : varianceThreshold },
            { preserveScroll: true }
        );
    };

    const openConfirm = () => {
        form.reset();
        form.clearErrors();
        setShowConfirm(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/office/settings/reset-stock', {
            preserveScroll: true,
            onSuccess: () => setShowConfirm(false),
        });
    };

    const openCatalogueConfirm = () => {
        catalogueForm.reset();
        catalogueForm.clearErrors();
        setShowCatalogueConfirm(true);
    };

    const submitCatalogueReset = (e: React.FormEvent) => {
        e.preventDefault();
        catalogueForm.post('/office/settings/reset-catalogue', {
            preserveScroll: true,
            onSuccess: () => setShowCatalogueConfirm(false),
        });
    };

    return (
        <BackOfficeLayout>
            <Head title="Settings" />

            <div className="mb-8">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Settings</h1>
                <p className="text-sm text-slate-500 mt-1">Business-wide configuration and one-time maintenance actions.</p>
            </div>

            {flash?.success && (
                <div className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {flash.success}
                </div>
            )}

            <div className="mb-3">
                <h2 className="text-sm font-semibold text-slate-700 uppercase tracking-wider">Workflows</h2>
            </div>
            <div className="mb-8 bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                <label className="flex items-start justify-between gap-4 cursor-pointer">
                    <div>
                        <p className="text-sm font-medium text-slate-800">Require approval before dispatching a stock transfer</p>
                        <p className="text-xs text-slate-500 mt-1">
                            When off (default), a requested transfer can be dispatched straight away. When on, it must be
                            approved first.
                        </p>
                    </div>
                    <input
                        type="checkbox"
                        checked={workflows.stock_transfer_requires_approval}
                        onChange={(e) => toggleWorkflow('stock_transfer_requires_approval', e.target.checked)}
                        className="mt-1 w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 flex-shrink-0"
                    />
                </label>

                <div className="border-t border-slate-100 mt-5 pt-5">
                    <p className="text-sm font-medium text-slate-800">Require owner approval for large purchase orders</p>
                    <p className="text-xs text-slate-500 mt-1 mb-3">
                        A purchase order over this amount is held for an owner or manager to approve before it's sent to
                        the supplier. Leave blank to never require approval, regardless of order size.
                    </p>
                    <form onSubmit={savePoThreshold} className="flex items-center gap-2">
                        <input
                            type="number" step="0.01" min="0" placeholder="No threshold"
                            value={poThreshold}
                            onChange={(e) => setPoThreshold(e.target.value)}
                            className="w-40 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                        <button type="submit" className="btn-primary py-2 px-4">Save</button>
                    </form>
                </div>

                <div className="border-t border-slate-100 mt-5 pt-5">
                    <p className="text-sm font-medium text-slate-800">Require a recount above a variance threshold</p>
                    <p className="text-xs text-slate-500 mt-1 mb-3">
                        A counted item whose difference from system stock exceeds this percentage must be counted again
                        before its stock take can be approved. Leave blank to never require a recount.
                    </p>
                    <form onSubmit={saveVarianceThreshold} className="flex items-center gap-2">
                        <input
                            type="number" step="0.1" min="0" max="1000" placeholder="No threshold"
                            value={varianceThreshold}
                            onChange={(e) => setVarianceThreshold(e.target.value)}
                            className="w-40 text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                        />
                        <span className="text-sm text-slate-500">%</span>
                        <button type="submit" className="btn-primary py-2 px-4">Save</button>
                    </form>
                </div>
            </div>

            <div className="mb-3">
                <h2 className="text-sm font-semibold text-red-700 uppercase tracking-wider">Danger zone</h2>
            </div>

            <div className="bg-white rounded-2xl border border-red-100 shadow-sm p-5">
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Reset All Stock</p>
                        <p className="text-sm text-slate-500 mt-1 max-w-xl">
                            Sets every product's stock to <span className="font-semibold">0</span>, at every location, business-wide.
                            Written through the same ledger every stock change goes through, so it's fully auditable and every
                            connected till picks it up on its next sync. This is a <span className="font-semibold">one-time</span> action —
                            once run, it can never be run again for this business.
                        </p>
                        {stock_reset.done && (
                            <p className="text-xs text-slate-400 mt-3 flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-3.5 h-3.5 text-emerald-500 flex-shrink-0">
                                    <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clipRule="evenodd" />
                                </svg>
                                Already used{stock_reset.at ? ` — ${new Date(stock_reset.at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}` : ''}
                                {stock_reset.by ? ` by ${stock_reset.by}` : ''}
                            </p>
                        )}
                    </div>
                    <button
                        onClick={openConfirm}
                        disabled={stock_reset.done}
                        className="text-sm px-4 py-2.5 rounded-xl font-semibold bg-red-600 text-white hover:bg-red-700 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed flex-shrink-0"
                    >
                        {stock_reset.done ? 'Already used' : 'Reset All Stock'}
                    </button>
                </div>
            </div>

            <div className="bg-white rounded-2xl border border-red-100 shadow-sm p-5 mt-4">
                <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Reset Everything</p>
                        <p className="text-sm text-slate-500 mt-1 max-w-xl">
                            Permanently deletes every product, all stock, and the entire sales, purchase order, stock take and
                            transfer history for this business — <span className="font-semibold">including transactions and payments</span>,
                            fiscalised ones included. Nothing is archived; it's gone. Use this only for a genuine fresh start (e.g.
                            clearing test/dummy data before go-live), then re-upload your real catalogue from{' '}
                            <a href="/office/products" className="font-semibold underline">Products</a>. This is a{' '}
                            <span className="font-semibold">one-time</span> action, independent of Reset All Stock above — once run,
                            it can never be run again for this business.
                        </p>
                        {catalogue_reset.done && (
                            <p className="text-xs text-slate-400 mt-3 flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-3.5 h-3.5 text-emerald-500 flex-shrink-0">
                                    <path fillRule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clipRule="evenodd" />
                                </svg>
                                Already used{catalogue_reset.at ? ` — ${new Date(catalogue_reset.at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}` : ''}
                                {catalogue_reset.by ? ` by ${catalogue_reset.by}` : ''}
                            </p>
                        )}
                    </div>
                    <button
                        onClick={openCatalogueConfirm}
                        disabled={catalogue_reset.done}
                        className="text-sm px-4 py-2.5 rounded-xl font-semibold bg-red-600 text-white hover:bg-red-700 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed flex-shrink-0"
                    >
                        {catalogue_reset.done ? 'Already used' : 'Reset Everything'}
                    </button>
                </div>
            </div>

            <Modal show={showConfirm} onClose={() => setShowConfirm(false)} maxWidth="md">
                <form onSubmit={submit} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-2">Reset all stock to zero?</p>
                    <p className="text-sm text-slate-500 mb-4">
                        This zeroes every product's stock quantity at every location for this business. It cannot be undone, and
                        this action can only be used once — ever. Type <span className="font-mono font-semibold text-slate-700">{CONFIRM_WORD}</span> to confirm.
                    </p>
                    <input
                        type="text"
                        autoFocus
                        value={form.data.confirm}
                        onChange={(e) => form.setData('confirm', e.target.value)}
                        placeholder={CONFIRM_WORD}
                        className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500 font-mono"
                    />
                    {form.errors.confirm && <p className="text-xs text-red-500 mt-1">{form.errors.confirm}</p>}
                    {(form.errors as Record<string, string>).stock_reset && (
                        <p className="text-xs text-red-500 mt-1">{(form.errors as Record<string, string>).stock_reset}</p>
                    )}

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowConfirm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing || form.data.confirm !== CONFIRM_WORD}
                            className="text-sm px-4 py-2 rounded-xl font-semibold bg-red-600 text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            {form.processing ? 'Resetting…' : 'Reset All Stock'}
                        </button>
                    </div>
                </form>
            </Modal>

            <Modal show={showCatalogueConfirm} onClose={() => setShowCatalogueConfirm(false)} maxWidth="md">
                <form onSubmit={submitCatalogueReset} className="p-6">
                    <p className="text-base font-semibold text-slate-800 mb-2">Delete everything?</p>
                    <p className="text-sm text-slate-500 mb-4">
                        This permanently deletes every product, all stock, and this business's entire sales, purchase order, stock
                        take and transfer history — <span className="font-semibold">transactions and payments included</span>. There
                        is no undo, and it can only be used once — ever. Type{' '}
                        <span className="font-mono font-semibold text-slate-700">{CATALOGUE_CONFIRM_PHRASE}</span> to confirm.
                    </p>
                    <input
                        type="text"
                        autoFocus
                        value={catalogueForm.data.confirm}
                        onChange={(e) => catalogueForm.setData('confirm', e.target.value)}
                        placeholder={CATALOGUE_CONFIRM_PHRASE}
                        className="w-full text-sm rounded-xl border border-slate-200 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500 font-mono"
                    />
                    {catalogueForm.errors.confirm && <p className="text-xs text-red-500 mt-1">{catalogueForm.errors.confirm}</p>}
                    {(catalogueForm.errors as Record<string, string>).catalogue_reset && (
                        <p className="text-xs text-red-500 mt-1">{(catalogueForm.errors as Record<string, string>).catalogue_reset}</p>
                    )}

                    <div className="mt-6 flex justify-end gap-2">
                        <button type="button" onClick={() => setShowCatalogueConfirm(false)} className="text-sm px-4 py-2 rounded-xl text-slate-600 hover:bg-slate-100">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={catalogueForm.processing || catalogueForm.data.confirm !== CATALOGUE_CONFIRM_PHRASE}
                            className="text-sm px-4 py-2 rounded-xl font-semibold bg-red-600 text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            {catalogueForm.processing ? 'Deleting…' : 'Delete Everything'}
                        </button>
                    </div>
                </form>
            </Modal>
        </BackOfficeLayout>
    );
}
