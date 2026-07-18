import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const [showPassword, setShowPassword] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <>
            <Head title="Log in" />

            <div className="relative flex min-h-screen w-full overflow-hidden bg-slate-950 selection:bg-indigo-500 selection:text-white">
                {/* ============== LEFT BRAND PANEL ============== */}
                <div className="relative hidden lg:flex lg:w-1/2 flex-col justify-between overflow-hidden bg-gradient-to-br from-indigo-700 via-purple-700 to-fuchsia-700 p-12 text-white">
                    {/* Decorative blobs */}
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                        <div className="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-white/10 blur-3xl" />
                        <div className="absolute bottom-0 right-0 h-[28rem] w-[28rem] rounded-full bg-fuchsia-400/20 blur-3xl" />
                        <div
                            className="absolute inset-0 opacity-[0.06]"
                            style={{
                                backgroundImage:
                                    'linear-gradient(rgba(255,255,255,1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,1) 1px, transparent 1px)',
                                backgroundSize: '40px 40px',
                            }}
                        />
                    </div>

                    {/* Logo + brand */}
                    <div className="relative z-10 flex items-center gap-3">
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/15 backdrop-blur-sm ring-1 ring-white/20">
                            <svg className="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M3 3h2l.4 2M7 13h10l4-8H5.4" />
                                <circle cx="9" cy="20" r="1.5" />
                                <circle cx="17" cy="20" r="1.5" />
                                <path d="M7 13L5.4 5" />
                            </svg>
                        </div>
                        <span className="text-2xl font-extrabold tracking-tight">SmartPOS</span>
                    </div>

                    {/* Tagline / marketing */}
                    <div className="relative z-10 space-y-6">
                        <h1 className="text-4xl xl:text-5xl font-extrabold leading-tight tracking-tight">
                            Run your business <br />
                            <span className="bg-gradient-to-r from-amber-200 to-pink-200 bg-clip-text text-transparent">
                                smarter, faster.
                            </span>
                        </h1>
                        <p className="max-w-md text-base text-indigo-100/90">
                            The all-in-one point-of-sale platform to manage sales, devices,
                            inventory and customers — from anywhere.
                        </p>

                        <div className="grid max-w-md grid-cols-1 gap-3 pt-4 sm:grid-cols-2">
                            {[
                                { label: 'Real-time analytics', icon: 'M3 3v18h18M7 14l4-4 4 4 5-5' },
                                { label: 'Multi-device sync', icon: 'M4 6h16M4 12h16M4 18h10' },
                                { label: 'Secure payments', icon: 'M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z' },
                                { label: 'Smart inventory', icon: 'M20 7L12 3 4 7m16 0v10l-8 4m8-14L12 11m0 0L4 7m8 4v10' },
                            ].map((f) => (
                                <div key={f.label} className="flex items-center gap-2.5 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 backdrop-blur-sm">
                                    <svg className="h-4 w-4 flex-shrink-0 text-amber-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d={f.icon} />
                                    </svg>
                                    <span className="text-sm font-medium text-white/90">{f.label}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Footer */}
                    <div className="relative z-10 text-xs text-indigo-100/70">
                        &copy; {new Date().getFullYear()} SmartPOS. All rights reserved.
                    </div>
                </div>

                {/* ============== RIGHT FORM PANEL ============== */}
                <div className="relative flex w-full flex-1 flex-col items-center justify-center p-6 sm:p-10">
                    {/* Mobile background accents */}
                    <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden lg:hidden">
                        <div className="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-indigo-500/30 blur-3xl" />
                        <div className="absolute -bottom-32 -right-24 h-96 w-96 rounded-full bg-purple-500/20 blur-3xl" />
                    </div>

                    <div className="relative z-10 w-full max-w-md">
                        {/* Mobile-only brand */}
                        <div className="mb-8 flex items-center justify-center gap-2.5 lg:hidden">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/30">
                                <svg className="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M3 3h2l.4 2M7 13h10l4-8H5.4" />
                                    <circle cx="9" cy="20" r="1.5" />
                                    <circle cx="17" cy="20" r="1.5" />
                                </svg>
                            </div>
                            <span className="text-xl font-extrabold tracking-tight text-white">SmartPOS</span>
                        </div>

                        {/* Heading */}
                        <div className="mb-8">
                            <h2 className="text-3xl font-extrabold tracking-tight text-white">
                                Welcome back
                            </h2>
                            <p className="mt-2 text-sm text-slate-400">
                                Sign in to your dashboard to continue managing your business.
                            </p>
                        </div>

                        {/* Status message */}
                        {status && (
                            <div className="mb-6 flex items-start gap-2.5 rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-3.5 text-sm text-emerald-300 backdrop-blur-sm">
                                <svg className="mt-0.5 h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                    <polyline points="22 4 12 14.01 9 11.01" />
                                </svg>
                                <span className="font-medium">{status}</span>
                            </div>
                        )}

                        <form onSubmit={submit} className="space-y-5">
                            {/* Email field */}
                            <div>
                                <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-slate-300">
                                    Email address
                                </label>
                                <div className="group relative">
                                    <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 transition-colors group-focus-within:text-indigo-400">
                                        <svg className="h-4.5 w-4.5" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <rect x="3" y="5" width="18" height="14" rx="2" />
                                            <path d="m3 7 9 6 9-6" />
                                        </svg>
                                    </span>
                                    <input
                                        id="email"
                                        type="email"
                                        name="email"
                                        autoFocus
                                        autoComplete="username"
                                        placeholder="you@company.com"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        className="block w-full rounded-xl border border-white/10 bg-white/[0.04] py-3 pl-11 pr-3.5 text-sm text-white placeholder-slate-500 shadow-inner backdrop-blur-sm transition duration-200 focus:border-indigo-400 focus:bg-white/[0.06] focus:outline-none focus:ring-2 focus:ring-indigo-400/30"
                                    />
                                </div>
                                <InputError message={errors.email} className="mt-2 !text-red-400" />
                            </div>

                            {/* Password field */}
                            <div>
                                <div className="mb-1.5 flex items-center justify-between">
                                    <label htmlFor="password" className="block text-sm font-medium text-slate-300">
                                        Password
                                    </label>
                                    {canResetPassword && (
                                        <Link
                                            href={route('password.request')}
                                            className="text-xs font-medium text-indigo-300 transition-colors hover:text-indigo-200"
                                        >
                                            Forgot password?
                                        </Link>
                                    )}
                                </div>
                                <div className="group relative">
                                    <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 transition-colors group-focus-within:text-indigo-400">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" />
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                        </svg>
                                    </span>
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        autoComplete="current-password"
                                        placeholder="••••••••"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="block w-full rounded-xl border border-white/10 bg-white/[0.04] py-3 pl-11 pr-11 text-sm text-white placeholder-slate-500 shadow-inner backdrop-blur-sm transition duration-200 focus:border-indigo-400 focus:bg-white/[0.06] focus:outline-none focus:ring-2 focus:ring-indigo-400/30"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword((v) => !v)}
                                        tabIndex={-1}
                                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                                        className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-500 transition-colors hover:text-indigo-300"
                                    >
                                        {showPassword ? (
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.77 19.77 0 0 1 4.22-5.36" />
                                                <path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.59 19.59 0 0 1-2.16 3.19" />
                                                <path d="M14.12 14.12a3 3 0 0 1-4.24-4.24" />
                                                <line x1="1" y1="1" x2="23" y2="23" />
                                            </svg>
                                        ) : (
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" />
                                                <circle cx="12" cy="12" r="3" />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                                <InputError message={errors.password} className="mt-2 !text-red-400" />
                            </div>

                            {/* Remember me */}
                            <div className="flex items-center justify-between">
                                <label className="group flex cursor-pointer select-none items-center gap-2">
                                    <input
                                        type="checkbox"
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) =>
                                            setData('remember', (e.target.checked || false) as false)
                                        }
                                        className="h-4 w-4 cursor-pointer rounded border-white/20 bg-white/10 text-indigo-500 transition focus:ring-2 focus:ring-indigo-400/40 focus:ring-offset-0"
                                    />
                                    <span className="text-sm text-slate-400 transition-colors group-hover:text-slate-200">
                                        Remember me for 30 days
                                    </span>
                                </label>
                            </div>

                            {/* Submit */}
                            <button
                                type="submit"
                                disabled={processing}
                                className="group relative flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/30 transition-all duration-300 hover:from-indigo-400 hover:to-purple-500 hover:shadow-indigo-500/50 focus:outline-none focus:ring-2 focus:ring-indigo-400/50 focus:ring-offset-2 focus:ring-offset-slate-950 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? (
                                    <>
                                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                                        </svg>
                                        <span>Signing in...</span>
                                    </>
                                ) : (
                                    <>
                                        <span>Sign in</span>
                                        <svg className="h-4 w-4 transition-transform duration-300 group-hover:translate-x-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                            <line x1="5" y1="12" x2="19" y2="12" />
                                            <polyline points="12 5 19 12 12 19" />
                                        </svg>
                                    </>
                                )}
                            </button>

                            <p className="text-center text-sm text-slate-400">
                                Business owner or employee?{' '}
                                <Link
                                    href={route('office.login')}
                                    className="font-medium text-indigo-300 transition-colors hover:text-indigo-200"
                                >
                                    Sign in to your Back Office
                                </Link>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </>
    );
}
