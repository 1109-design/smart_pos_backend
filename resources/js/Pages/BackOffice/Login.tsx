import React from 'react';
import { Head, useForm } from '@inertiajs/react';

const PAIRING_CODE_STORAGE_KEY = 'smartpos.office.pairing_code';

export default function BackOfficeLogin() {
    const { data, setData, post, processing, errors } = useForm({
        pairing_code: localStorage.getItem(PAIRING_CODE_STORAGE_KEY) ?? '',
        email:        '',
        password:     '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/office/login', {
            onSuccess: () => localStorage.setItem(PAIRING_CODE_STORAGE_KEY, data.pairing_code),
        });
    };

    return (
        <div className="min-h-screen flex text-slate-100 bg-slate-900 font-sans selection:bg-emerald-500 selection:text-white">
            <Head title="Back Office — Sign In" />

            {/* Left Side: Brand / Visual (Hidden on mobile) */}
            <div className="hidden lg:flex lg:w-1/2 relative overflow-hidden bg-slate-950 items-center justify-center">
                {/* Abstract animated gradient background */}
                <div className="absolute inset-0 z-0">
                    <div className="absolute top-[-10%] left-[-10%] w-[50%] h-[50%] rounded-full bg-emerald-600/30 blur-[120px] animate-pulse" style={{ animationDuration: '4s' }}></div>
                    <div className="absolute bottom-[-10%] right-[-10%] w-[60%] h-[60%] rounded-full bg-teal-800/40 blur-[140px] animate-pulse" style={{ animationDuration: '7s' }}></div>
                </div>

                <div className="relative z-10 p-12 max-w-xl text-center">
                    <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-emerald-400 to-emerald-700 shadow-[0_0_40px_rgba(16,185,129,0.4)] flex items-center justify-center mx-auto mb-8 animate-[bounce_3s_ease-in-out_infinite]">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="white" className="w-10 h-10">
                            <path d="M2.879 7.121A3 3 0 0 0 7.5 6.66a2.997 2.997 0 0 0 2.5 1.34 2.997 2.997 0 0 0 2.5-1.34 3 3 0 1 0 4.622-3.78l-.293-.293A2 2 0 0 0 15.415 2H4.585a2 2 0 0 0-1.414.586l-.292.292a3 3 0 0 0 0 4.243ZM3 12v5h5v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h5v-5a3 3 0 0 1-3-3 3 3 0 0 1-2.5 1.338A3 3 0 0 1 9.5 9 3 3 0 0 1 7 10.338 3 3 0 0 1 3 9v3Z" />
                        </svg>
                    </div>
                    <h1 className="text-4xl md:text-5xl font-extrabold tracking-tight text-white mb-6">
                        Welcome to <span className="text-transparent bg-clip-text bg-gradient-to-r from-emerald-400 to-teal-200">SmartPOS</span>
                    </h1>
                    <p className="text-lg text-slate-400 leading-relaxed font-light">
                        The ultimate back-office command center. Manage your inventory, oversee staff, and analyze sales performance in real-time.
                    </p>
                </div>
            </div>

            {/* Right Side: Login Form */}
            <div className="w-full lg:w-1/2 flex items-center justify-center p-6 sm:p-12 relative overflow-y-auto">
                {/* Mobile Background Elements (visible only on small screens) */}
                <div className="absolute inset-0 z-0 lg:hidden overflow-hidden pointer-events-none">
                    <div className="absolute top-[-10%] right-[-10%] w-[80%] h-[40%] rounded-full bg-emerald-900/30 blur-[100px]"></div>
                    <div className="absolute bottom-[-10%] left-[-10%] w-[60%] h-[40%] rounded-full bg-teal-900/20 blur-[100px]"></div>
                </div>

                <div className="w-full max-w-md relative z-10 py-10">
                    {/* Mobile Logo */}
                    <div className="lg:hidden flex items-center gap-4 mb-10">
                         <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-400 to-emerald-600 shadow-[0_0_20px_rgba(16,185,129,0.3)] flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="white" className="w-6 h-6">
                                <path d="M2.879 7.121A3 3 0 0 0 7.5 6.66a2.997 2.997 0 0 0 2.5 1.34 2.997 2.997 0 0 0 2.5-1.34 3 3 0 1 0 4.622-3.78l-.293-.293A2 2 0 0 0 15.415 2H4.585a2 2 0 0 0-1.414.586l-.292.292a3 3 0 0 0 0 4.243ZM3 12v5h5v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h5v-5a3 3 0 0 1-3-3 3 3 0 0 1-2.5 1.338A3 3 0 0 1 9.5 9 3 3 0 0 1 7 10.338 3 3 0 0 1 3 9v3Z" />
                            </svg>
                        </div>
                        <div>
                            <h2 className="text-2xl font-bold text-white tracking-tight">SmartPOS</h2>
                            <p className="text-sm text-emerald-400 font-medium">Back Office</p>
                        </div>
                    </div>

                    {/* Glass Panel */}
                    <div className="bg-slate-800/60 backdrop-blur-xl border border-slate-700/50 p-8 sm:p-10 rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.3)] transition-all duration-500 hover:shadow-[0_8px_40px_rgb(0,0,0,0.5)]">
                        <div className="mb-8">
                            <h2 className="text-2xl font-bold text-white mb-2">Sign in</h2>
                            <p className="text-slate-400 text-sm">Enter your credentials to access your dashboard.</p>
                        </div>

                        <form onSubmit={submit} className="space-y-6">
                            <div className="group">
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-widest mb-2 transition-colors group-focus-within:text-emerald-400">
                                    Business Pairing Code
                                </label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        value={data.pairing_code}
                                        onChange={(e) => setData('pairing_code', e.target.value.toUpperCase())}
                                        className="w-full bg-slate-900/50 border border-slate-700/80 text-white text-sm rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all font-mono tracking-widest placeholder-slate-600"
                                        placeholder="XXXXXX"
                                        maxLength={20}
                                        required
                                    />
                                    {errors.pairing_code && (
                                        <p className="absolute -bottom-5 left-0 text-red-400 text-xs font-medium">{errors.pairing_code}</p>
                                    )}
                                </div>
                            </div>

                            <div className="group">
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-widest mb-2 transition-colors group-focus-within:text-emerald-400">
                                    Email Address
                                </label>
                                <div className="relative">
                                    <input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="w-full bg-slate-900/50 border border-slate-700/80 text-white text-sm rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all placeholder-slate-600"
                                        placeholder="admin@yourbusiness.com"
                                        required
                                    />
                                    {errors.email && (
                                        <p className="absolute -bottom-5 left-0 text-red-400 text-xs font-medium">{errors.email}</p>
                                    )}
                                </div>
                            </div>

                            <div className="group">
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-widest mb-2 transition-colors group-focus-within:text-emerald-400">
                                    Password
                                </label>
                                <div className="relative">
                                    <input
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="w-full bg-slate-900/50 border border-slate-700/80 text-white text-sm rounded-xl px-4 py-3.5 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all placeholder-slate-600"
                                        placeholder="••••••••"
                                        required
                                    />
                                    {errors.password && (
                                        <p className="absolute -bottom-5 left-0 text-red-400 text-xs font-medium">{errors.password}</p>
                                    )}
                                </div>
                            </div>

                            <div className="pt-4">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="relative w-full overflow-hidden rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-3.5 text-sm font-bold text-white shadow-[0_0_15px_rgba(16,185,129,0.3)] transition-all duration-300 hover:from-emerald-500 hover:to-teal-500 hover:shadow-[0_0_25px_rgba(16,185,129,0.5)] hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-70 disabled:hover:from-emerald-600 disabled:hover:to-teal-600 disabled:hover:shadow-none disabled:hover:translate-y-0 active:scale-[0.98]"
                                >
                                    <span className="relative z-10 flex items-center justify-center gap-2">
                                        {processing ? (
                                            <>
                                                <svg className="h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Authenticating...
                                            </>
                                        ) : (
                                            'Sign into Workspace'
                                        )}
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    {/* Footer text */}
                    <p className="text-center text-xs text-slate-500 mt-8 font-medium tracking-wide">
                        &copy; {new Date().getFullYear()} SmartPOS Technologies
                    </p>
                </div>
            </div>
        </div>
    );
}
