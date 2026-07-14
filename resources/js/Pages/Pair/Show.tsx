import React, { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    businessName: string;
    isActive: boolean;
    pairingCode: string;
    ownerEmail: string;
    deepLink: string;
    downloadUrl: string;
}

export default function PairShow({ businessName, isActive, pairingCode, ownerEmail, deepLink, downloadUrl }: Props) {
    const [showFallback, setShowFallback] = useState(false);

    useEffect(() => {
        if (!isActive) return;

        // Try to hand off straight to the app. If it's not installed, the
        // browser just stays here and we reveal the fallback below.
        window.location.href = deepLink;

        const timer = setTimeout(() => setShowFallback(true), 1500);
        return () => clearTimeout(timer);
    }, [isActive, deepLink]);

    return (
        <div className="relative flex min-h-screen w-full items-center justify-center overflow-hidden bg-slate-950 px-6 py-16">
            <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                <div className="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-indigo-600/20 blur-3xl" />
                <div className="absolute bottom-0 right-0 h-[28rem] w-[28rem] rounded-full bg-fuchsia-500/10 blur-3xl" />
            </div>

            <Head title={`Pair — ${businessName}`} />

            <div className="relative z-10 w-full max-w-sm rounded-3xl bg-white/5 p-8 text-center ring-1 ring-white/10 backdrop-blur-sm">
                <h1 className="text-lg font-bold text-white">{businessName}</h1>

                {!isActive ? (
                    <p className="mt-4 text-sm text-white/60">This business isn&apos;t currently active. Contact support for help.</p>
                ) : !showFallback ? (
                    <div className="mt-6 flex flex-col items-center gap-3 text-sm text-white/60">
                        <div className="h-6 w-6 animate-spin rounded-full border-2 border-white/20 border-t-white/70" />
                        Opening SmartPOS…
                    </div>
                ) : (
                    <>
                        <p className="mt-1 text-sm text-white/60">
                            If SmartPOS didn&apos;t open automatically, install it below, then enter these details on the pairing screen.
                        </p>

                        <div className="mt-6 space-y-3 text-left">
                            <div className="rounded-xl bg-white/5 px-4 py-3 ring-1 ring-white/10">
                                <p className="text-[11px] uppercase tracking-wide text-white/40">Pairing Code</p>
                                <p className="mt-0.5 font-mono text-lg font-semibold text-white">{pairingCode}</p>
                            </div>
                            <div className="rounded-xl bg-white/5 px-4 py-3 ring-1 ring-white/10">
                                <p className="text-[11px] uppercase tracking-wide text-white/40">Admin Email</p>
                                <p className="mt-0.5 break-all text-sm font-medium text-white">{ownerEmail}</p>
                            </div>
                        </div>

                        <a
                            href={downloadUrl}
                            className="mt-6 block w-full rounded-xl bg-white py-3 text-sm font-semibold text-slate-900 transition-opacity hover:opacity-90"
                        >
                            Download SmartPOS
                        </a>
                    </>
                )}
            </div>
        </div>
    );
}
