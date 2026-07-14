import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    available: boolean;
    downloadUrl: string;
    qrCode: string;
}

export default function DownloadShow({ available, downloadUrl, qrCode }: Props) {
    return (
        <div className="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-slate-950 px-6 py-16">
            <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                <div className="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-indigo-600/20 blur-3xl" />
                <div className="absolute bottom-0 right-0 h-[28rem] w-[28rem] rounded-full bg-fuchsia-500/10 blur-3xl" />
            </div>

            <Head title="Download SmartPOS" />

            <div className="relative z-10 w-full max-w-sm rounded-3xl bg-white/5 p-8 text-center ring-1 ring-white/10 backdrop-blur-sm">
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
                    <svg className="h-7 w-7 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M3 3h2l.4 2M7 13h10l4-8H5.4" />
                        <circle cx="9" cy="20" r="1.5" />
                        <circle cx="17" cy="20" r="1.5" />
                        <path d="M7 13L5.4 5" />
                    </svg>
                </div>
                <h1 className="text-xl font-bold text-white">Get SmartPOS</h1>
                <p className="mt-1 text-sm text-white/60">Scan this code with your phone, or tap the button below.</p>

                <div className="mx-auto mt-6 w-48 rounded-2xl bg-white p-3">
                    <img src={qrCode} alt="QR code linking to this download page" className="h-full w-full" />
                </div>

                {available ? (
                    <a
                        href={downloadUrl}
                        className="mt-6 block w-full rounded-xl bg-white py-3 text-sm font-semibold text-slate-900 transition-opacity hover:opacity-90"
                    >
                        Download APK
                    </a>
                ) : (
                    <div className="mt-6 rounded-xl bg-amber-400/10 px-4 py-3 text-sm text-amber-200 ring-1 ring-amber-400/20">
                        The app isn&apos;t available for download yet — check back soon.
                    </div>
                )}
            </div>
        </div>
    );
}
