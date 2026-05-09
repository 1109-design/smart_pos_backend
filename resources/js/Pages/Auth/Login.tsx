import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

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

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            {status && (
                <div className="mb-6 text-sm font-medium text-green-400 bg-green-500/10 border border-green-500/20 p-4 rounded-xl backdrop-blur-md">
                    {status}
                </div>
            )}

            <div className="mb-8 text-center">
                <h2 className="text-3xl font-extrabold text-white tracking-tight">Welcome Back</h2>
                <p className="mt-2 text-sm text-indigo-200/80 font-medium">Sign in to your dashboard to continue</p>
            </div>

            <form onSubmit={submit} className="space-y-6">
                <div>
                    <InputLabel htmlFor="email" value="Email Address" className="!text-indigo-100 !text-sm !font-medium mb-1.5" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="block w-full !bg-white/5 !border-white/10 !text-white !shadow-inner !focus:border-indigo-400 !focus:ring-indigo-400/30 backdrop-blur-sm transition-all duration-300 !rounded-xl"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} className="mt-2 !text-red-400" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Password" className="!text-indigo-100 !text-sm !font-medium mb-1.5" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="block w-full !bg-white/5 !border-white/10 !text-white !shadow-inner !focus:border-indigo-400 !focus:ring-indigo-400/30 backdrop-blur-sm transition-all duration-300 !rounded-xl"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError message={errors.password} className="mt-2 !text-red-400" />
                </div>

                <div className="flex items-center justify-between">
                    <label className="flex items-center cursor-pointer group">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            className="!bg-white/10 !border-white/20 !text-indigo-500 focus:!ring-indigo-500/50 group-hover:!border-white/40 transition-colors !rounded"
                            onChange={(e) =>
                                setData(
                                    'remember',
                                    (e.target.checked || false) as false,
                                )
                            }
                        />
                        <span className="ms-2 text-sm text-indigo-200/80 group-hover:text-indigo-100 transition-colors">
                            Remember me
                        </span>
                    </label>

                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="text-sm font-medium text-indigo-300 hover:text-indigo-100 transition-colors focus:outline-none focus:underline"
                        >
                            Forgot password?
                        </Link>
                    )}
                </div>

                <div className="pt-2">
                    <PrimaryButton 
                        className="w-full flex justify-center py-3 !bg-gradient-to-r !from-indigo-500 !to-purple-600 hover:!from-indigo-400 hover:!to-purple-500 !border-transparent !rounded-xl !shadow-[0_0_20px_rgba(99,102,241,0.4)] hover:!shadow-[0_0_25px_rgba(99,102,241,0.6)] !text-white !font-bold tracking-wide transform transition-all duration-300 hover:-translate-y-0.5" 
                        disabled={processing}
                    >
                        {processing ? 'Signing in...' : 'Sign In'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
