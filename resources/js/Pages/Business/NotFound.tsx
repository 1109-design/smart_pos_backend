import React from 'react';
import { Head } from '@inertiajs/react';

export default function BusinessNotFound() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 text-center text-slate-900 dark:bg-slate-950 dark:text-slate-100">
            <Head title="Business not found" />
            <div className="w-full max-w-sm rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200/70 dark:bg-slate-900 dark:ring-white/10">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-2xl dark:bg-white/10" aria-hidden="true">
                    ?
                </div>
                <h1 className="mt-4 text-lg font-bold">We couldn&apos;t find this business</h1>
                <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    The page this code points to isn&apos;t available right now. Your receipt is still valid. Please check with the store.
                </p>
            </div>
        </div>
    );
}
