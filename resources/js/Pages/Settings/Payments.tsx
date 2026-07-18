import React from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

interface PaymentInfo {
    ecocash_number: string | null;
    ecocash_name: string | null;
    whatsapp_number: string | null;
    instructions: string | null;
}

interface Props {
    payment: PaymentInfo;
}

const inputCls = 'form-input';

export default function PaymentSettings({ payment }: Props) {
    const { props } = usePage() as any;
    const success = props.flash?.success;

    const form = useForm({
        ecocash_number: payment.ecocash_number ?? '',
        ecocash_name: payment.ecocash_name ?? '',
        whatsapp_number: payment.whatsapp_number ?? '',
        instructions: payment.instructions ?? '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.put('/settings/payments');
    }

    return (
        <AppLayout>
            <Head title="Payment Settings" />

            <div className="mb-8">
                <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Payment Settings</h1>
                <p className="text-sm text-slate-500 mt-1">
                    Shown to merchants on the app's lock screen when their subscription expires:
                    pay via EcoCash, send proof of payment on WhatsApp, receive an activation code.
                </p>
            </div>

            {success && (
                <div className="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    {success}
                </div>
            )}

            <div className="bg-white rounded-2xl border border-gray-200 shadow-sm p-6 max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-medium text-gray-500 mb-1">EcoCash Number</label>
                            <input
                                value={form.data.ecocash_number}
                                onChange={e => form.setData('ecocash_number', e.target.value)}
                                placeholder="e.g. 0771 234 567"
                                className={inputCls}
                            />
                            {form.errors.ecocash_number && <p className="text-red-500 text-xs mt-1">{form.errors.ecocash_number}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-500 mb-1">EcoCash Account Name</label>
                            <input
                                value={form.data.ecocash_name}
                                onChange={e => form.setData('ecocash_name', e.target.value)}
                                placeholder="Name merchants should see when paying"
                                className={inputCls}
                            />
                            {form.errors.ecocash_name && <p className="text-red-500 text-xs mt-1">{form.errors.ecocash_name}</p>}
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-500 mb-1">WhatsApp Number (for proof of payment)</label>
                        <input
                            value={form.data.whatsapp_number}
                            onChange={e => form.setData('whatsapp_number', e.target.value)}
                            placeholder="International format, e.g. +263 771 234 567"
                            className={inputCls}
                        />
                        {form.errors.whatsapp_number && <p className="text-red-500 text-xs mt-1">{form.errors.whatsapp_number}</p>}
                        <p className="text-xs text-gray-400 mt-1">
                            Used to build the "Send proof on WhatsApp" button in the app — include the country code.
                        </p>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-500 mb-1">Extra Instructions (optional)</label>
                        <textarea
                            value={form.data.instructions}
                            onChange={e => form.setData('instructions', e.target.value)}
                            placeholder="e.g. Include your business name in the payment reference."
                            rows={3}
                            className={inputCls}
                        />
                        {form.errors.instructions && <p className="text-red-500 text-xs mt-1">{form.errors.instructions}</p>}
                    </div>

                    <div className="flex justify-end pt-2 border-t border-gray-100">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="text-sm font-medium text-white bg-indigo-600 px-5 py-2 rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                        >
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
