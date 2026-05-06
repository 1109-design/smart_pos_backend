import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

interface Business {
    id?: string;
    business_name?: string;
    owner_email?: string;
    tier?: string;
    country?: string;
    currency_code?: string;
}

interface Tier {
    key: string;
    label: string;
    price: string;
}

interface Props {
    business?: Business;
    tiers?: Tier[];
}

export default function BusinessForm({ business, tiers = [] }: Props) {
    const isEdit = !!business?.id;

    const { data, setData, post, put, processing, errors } = useForm({
        business_name: business?.business_name ?? '',
        owner_email:   business?.owner_email ?? '',
        tier:          business?.tier ?? (tiers[0]?.key ?? 'starter'),
        country:       business?.country ?? '',
        currency_code: business?.currency_code ?? 'USD',
        admin_name:    '',
        admin_pin:     '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (isEdit) {
            put(`/businesses/${business!.id}`);
        } else {
            post('/businesses');
        }
    };

    return (
        <AppLayout>
            <Head title={isEdit ? 'Edit Business' : 'New Business'} />

            <div className="flex items-center gap-2 text-sm text-slate-400 mb-4">
                <Link href="/businesses" className="hover:text-slate-600 transition-colors">Businesses</Link>
                <span>/</span>
                <span className="text-slate-600 font-medium">{isEdit ? 'Edit' : 'New Business'}</span>
            </div>

            <h1 className="text-2xl font-bold text-slate-900 tracking-tight mb-8">
                {isEdit ? 'Edit Business' : 'Create New Business'}
            </h1>

            <form onSubmit={submit} className="max-w-lg space-y-6">
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-5">
                    <div>
                        <label className="form-label">Business Name</label>
                        <input
                            type="text"
                            value={data.business_name}
                            onChange={(e) => setData('business_name', e.target.value)}
                            placeholder="Acme Store"
                            className="form-input"
                            required
                        />
                        {errors.business_name && <p className="text-red-500 text-xs mt-1.5">{errors.business_name}</p>}
                    </div>

                    <div>
                        <label className="form-label">Owner Email</label>
                        <input
                            type="email"
                            value={data.owner_email}
                            onChange={(e) => setData('owner_email', e.target.value)}
                            placeholder="owner@example.com"
                            className="form-input"
                            required
                        />
                        {errors.owner_email && <p className="text-red-500 text-xs mt-1.5">{errors.owner_email}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="form-label">Tier</label>
                            <select
                                value={data.tier}
                                onChange={(e) => setData('tier', e.target.value)}
                                className="form-input"
                            >
                                {tiers.map(t => (
                                    <option key={t.key} value={t.key}>{t.label} — {t.price}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="form-label">Currency</label>
                            <input
                                type="text"
                                value={data.currency_code}
                                onChange={(e) => setData('currency_code', e.target.value)}
                                maxLength={10}
                                placeholder="USD"
                                className="form-input"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="form-label">Country Code</label>
                        <input
                            type="text"
                            value={data.country}
                            onChange={(e) => setData('country', e.target.value)}
                            maxLength={2}
                            placeholder="US"
                            className="form-input"
                        />
                    </div>
                </div>

                {!isEdit && (
                    <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-5">
                        <div>
                            <p className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">First Admin Account</p>
                            <div className="space-y-5">
                                <div>
                                    <label className="form-label">Admin Name</label>
                                    <input
                                        type="text"
                                        value={data.admin_name}
                                        onChange={(e) => setData('admin_name', e.target.value)}
                                        placeholder="John Doe"
                                        className="form-input"
                                        required
                                    />
                                    {errors.admin_name && <p className="text-red-500 text-xs mt-1.5">{errors.admin_name}</p>}
                                </div>

                                <div>
                                    <label className="form-label">Admin PIN <span className="text-slate-400 font-normal">(4 digits)</span></label>
                                    <input
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={4}
                                        value={data.admin_pin}
                                        onChange={(e) => setData('admin_pin', e.target.value.replace(/\D/g, ''))}
                                        placeholder="••••"
                                        className="form-input"
                                        required
                                    />
                                    {errors.admin_pin && <p className="text-red-500 text-xs mt-1.5">{errors.admin_pin}</p>}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                <div className="flex justify-end gap-3">
                    <Link href="/businesses" className="btn-secondary">
                        Cancel
                    </Link>
                    <button type="submit" disabled={processing} className="btn-primary disabled:opacity-50">
                        {isEdit ? 'Save Changes' : 'Create Business'}
                    </button>
                </div>
            </form>
        </AppLayout>
    );
}
