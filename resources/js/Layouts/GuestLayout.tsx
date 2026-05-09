import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 selection:bg-indigo-500 selection:text-white">
            <div className="relative w-full overflow-hidden rounded-2xl bg-white/10 px-8 py-10 shadow-2xl backdrop-blur-xl border border-white/10 sm:max-w-md sm:px-10 transition-all duration-500 hover:shadow-indigo-500/10 hover:border-white/20">
                <div className="mb-8 flex justify-center transform transition duration-500 hover:scale-105">
                    <Link href="/">
                        <ApplicationLogo className="h-20 w-20 fill-current text-indigo-400 drop-shadow-lg" />
                    </Link>
                </div>

                <div className="text-white/90">
                    {children}
                </div>
            </div>
        </div>
    );
}
