import React from 'react';
import { Head, useForm } from '@inertiajs/react';

export default function BackOfficeLogin() {
    const { data, setData, post, processing, errors } = useForm({
        pairing_code: '',
        email:        '',
        password:     '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/office/login');
    };

    return (
        <div className="min-h-screen bg-slate-900 flex items-center justify-center px-4">
            <Head title="Back Office — Sign In" />

            <div className="w-full max-w-sm">
                {/* Logo */}
                <div className="flex items-center justify-center gap-3 mb-8">
                    <div className="w-10 h-10 rounded-xl bg-emerald-600 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="white" className="w-5 h-5">
                            <path d="M2.879 7.121A3 3 0 0 0 7.5 6.66a2.997 2.997 0 0 0 2.5 1.34 2.997 2.997 0 0 0 2.5-1.34 3 3 0 1 0 4.622-3.78l-.293-.293A2 2 0 0 0 15.415 2H4.585a2 2 0 0 0-1.414.586l-.292.292a3 3 0 0 0 0 4.243ZM3 12v5h5v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h5v-5a3 3 0 0 1-3-3 3 3 0 0 1-2.5 1.338A3 3 0 0 1 9.5 9 3 3 0 0 1 7 10.338 3 3 0 0 1 3 9v3Z" />
                        </svg>
                    </div>
                    <div>
                        <p className="text-base font-bold text-white leading-none">SmartPOS</p>
                        <p className="text-xs text-slate-400 mt-0.5">Back Office Portal</p>
                    </div>
                </div>

                {/* Card */}
                <div className="bg-slate-800 rounded-2xl border border-slate-700 p-8">
                    <h1 className="text-xl font-semibold text-white mb-1">Sign in</h1>
                    <p className="text-sm text-slate-400 mb-6">Enter your business code and credentials</p>

                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                                Business Pairing Code
                            </label>
                            <input
                                type="text"
                                value={data.pairing_code}
                                onChange={(e) => setData('pairing_code', e.target.value.toUpperCase())}
                                className="w-full px-3 py-2.5 rounded-xl bg-slate-700 border border-slate-600 text-white placeholder-slate-500 text-sm font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                                placeholder="XXXXXX"
                                maxLength={20}
                                required
                            />
                            {errors.pairing_code && (
                                <p className="text-red-400 text-xs mt-1.5">{errors.pairing_code}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                                Email
                            </label>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="w-full px-3 py-2.5 rounded-xl bg-slate-700 border border-slate-600 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                                placeholder="you@business.com"
                                required
                            />
                            {errors.email && (
                                <p className="text-red-400 text-xs mt-1.5">{errors.email}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                                Password
                            </label>
                            <input
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                className="w-full px-3 py-2.5 rounded-xl bg-slate-700 border border-slate-600 text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                                placeholder="••••••••"
                                required
                            />
                            {errors.password && (
                                <p className="text-red-400 text-xs mt-1.5">{errors.password}</p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-sm font-semibold py-2.5 rounded-xl transition-colors mt-2"
                        >
                            {processing ? 'Signing in…' : 'Sign in'}
                        </button>
                    </form>
                </div>

            </div>
        </div>
    );
}
