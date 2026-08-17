import React, { PropsWithChildren, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';

interface BackofficeAuth {
    user_name: string;
    user_email: string;
    role: string | null;
    business_name: string;
    currency_code: string;
}

const IconChart = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path d="M12 9a1 1 0 0 1-1-1V3c0-.552.45-1.007.997-.93a7.004 7.004 0 0 1 5.933 5.933c.078.547-.378.997-.93.997h-5Z" />
        <path d="M8.003 4.07C8.55 3.994 9 4.449 9 5v5a1 1 0 0 0 1 1h5c.552 0 1.008.45.93.997A7 7 0 1 1 8.003 4.07Z" />
    </svg>
);

const IconDocumentReport = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-4.12-4.122A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0-6a.75.75 0 0 0 0 1.5h3a.75.75 0 0 0 0-1.5h-3Z" clipRule="evenodd" />
    </svg>
);

const IconUsers = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path d="M7 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM14.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM1.615 16.428a1.224 1.224 0 0 1-.569-1.175 6.002 6.002 0 0 1 11.908 0c.058.467-.172.92-.57 1.174A9.953 9.953 0 0 1 7 18a9.953 9.953 0 0 1-5.385-1.572ZM14.5 16h-.106c.07-.297.088-.611.048-.933a7.47 7.47 0 0 0-1.588-3.755 4.502 4.502 0 0 1 5.874 2.636.818.818 0 0 1-.36.98A7.465 7.465 0 0 1 14.5 16Z" />
    </svg>
);

const IconSignOut = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M3 4.25A2.25 2.25 0 0 1 5.25 2h5.5A2.25 2.25 0 0 1 13 4.25v2a.75.75 0 0 1-1.5 0v-2a.75.75 0 0 0-.75-.75h-5.5a.75.75 0 0 0-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 0 0 .75-.75v-2a.75.75 0 0 1 1.5 0v2A2.25 2.25 0 0 1 10.75 18h-5.5A2.25 2.25 0 0 1 3 15.75V4.25Z" clipRule="evenodd" />
        <path fillRule="evenodd" d="M19 10a.75.75 0 0 0-.75-.75H8.704l1.048-1.08a.75.75 0 1 0-1.08-1.04l-2.25 2.33a.75.75 0 0 0 0 1.04l2.25 2.33a.75.75 0 1 0 1.08-1.04l-1.048-1.08H18.25A.75.75 0 0 0 19 10Z" clipRule="evenodd" />
    </svg>
);

const IconReceipt = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M4.93 1.31a41.401 41.401 0 0 1 10.14 0C16.194 1.45 17 2.414 17 3.517V18.25a.75.75 0 0 1-1.075.676l-2.8-1.344-2.8 1.344a.75.75 0 0 1-.65 0l-2.8-1.344-2.8 1.344A.75.75 0 0 1 3 18.25V3.517c0-1.103.806-2.068 1.93-2.207Zm4.822 5.997a.75.75 0 1 0-1.004-1.114l-2.5 2.25a.75.75 0 0 0 0 1.114l2.5 2.25a.75.75 0 0 0 1.004-1.114L8.704 9.75h4.546a.75.75 0 0 0 0-1.5H8.704l1.048-.943Z" clipRule="evenodd" />
    </svg>
);

const IconBox = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path d="M3.75 3A1.75 1.75 0 0 0 2 4.75v3.26a3.235 3.235 0 0 1 1.75-.51h12.5c.644 0 1.245.188 1.75.51V4.75A1.75 1.75 0 0 0 16.25 3H3.75Z" />
        <path fillRule="evenodd" d="M3.75 9a1.75 1.75 0 0 0-1.75 1.75v4.5c0 .966.784 1.75 1.75 1.75h12.5a1.75 1.75 0 0 0 1.75-1.75v-4.5A1.75 1.75 0 0 0 16.25 9H3.75Zm4 3.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" clipRule="evenodd" />
    </svg>
);

const IconClock = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clipRule="evenodd" />
    </svg>
);

const IconMapPin = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path fillRule="evenodd" d="M9.69 18.933a.75.75 0 0 0 .62 0c.061-.027 5.44-2.503 8.157-6.803A6.66 6.66 0 0 0 19.5 8.5a9.5 9.5 0 1 0-19 0 6.66 6.66 0 0 0 1.033 3.63c2.717 4.3 8.096 6.776 8.157 6.803ZM10 11.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clipRule="evenodd" />
    </svg>
);

const IconTruck = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-4 h-4">
        <path d="M8.5 4.5a2.5 2.5 0 0 0-2.5 2.5v.5H2.75a.75.75 0 0 0 0 1.5H6v3H2.75a.75.75 0 0 0 0 1.5h.564a2.5 2.5 0 0 0 4.872 0h3.628a2.5 2.5 0 0 0 4.872 0H17a1 1 0 0 0 1-1v-2.382a1 1 0 0 0-.211-.615l-1.5-1.882a1 1 0 0 0-.782-.377H14V7A2.5 2.5 0 0 0 11.5 4.5h-3ZM5.5 14.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2Zm8.5 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z" />
    </svg>
);

const ALL_NAV = [
    { label: 'Dashboard',    href: '/office/dashboard',    Icon: IconChart,          roles: null },
    { label: 'Transactions', href: '/office/transactions', Icon: IconReceipt,        roles: null },
    { label: 'Shifts',       href: '/office/shifts',       Icon: IconClock,          roles: null },
    { label: 'Products',     href: '/office/products',     Icon: IconBox,            roles: ['business_owner', 'manager'] },
    { label: 'Combos',       href: '/office/combos',       Icon: IconBox,            roles: ['business_owner', 'manager'] },
    { label: 'Transfers',    href: '/office/transfers',    Icon: IconTruck,          roles: ['business_owner', 'manager'] },
    { label: 'Locations',    href: '/office/locations',    Icon: IconMapPin,         roles: ['business_owner', 'manager'] },
    { label: 'Reports',      href: '/office/reports',      Icon: IconDocumentReport, roles: null },
    { label: 'Users',        href: '/office/users',        Icon: IconUsers,          roles: ['business_owner', 'manager'] },
];

export default function BackOfficeLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage() as any;
    const auth: BackofficeAuth | null = props.backoffice_auth ?? null;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    const navItems = ALL_NAV.filter(({ roles }) => !roles || (auth?.role && roles.includes(auth.role)));

    const initials = auth?.user_name
        ? auth.user_name.split(' ').map((n: string) => n[0]).join('').slice(0, 2).toUpperCase()
        : '??';

    const roleBadge: Record<string, string> = {
        business_owner: 'Owner',
        manager:        'Manager',
        cashier:        'Cashier',
    };

    const logout = () => router.post('/office/logout');

    const NavContent = () => (
        <>
            <nav className="flex-1 px-3 py-5 space-y-0.5 overflow-y-auto">
                <p className="text-xs font-semibold text-slate-600 uppercase tracking-widest px-3 mb-2">Menu</p>
                {navItems.map(({ label, href, Icon }) => {
                    const active = url.startsWith(href);
                    return (
                        <Link
                            key={href}
                            href={href}
                            onClick={() => setSidebarOpen(false)}
                            className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 ${
                                active
                                    ? 'bg-white/10 text-white shadow-sm'
                                    : 'text-slate-400 hover:bg-white/5 hover:text-slate-200'
                            }`}
                        >
                            <span className={active ? 'text-emerald-400' : 'text-slate-500'}><Icon /></span>
                            {label}
                            {active && <span className="ml-auto w-1.5 h-1.5 rounded-full bg-emerald-400" />}
                        </Link>
                    );
                })}
            </nav>
            <div className="border-t border-slate-800 p-3 space-y-1">
                <div className="flex items-center gap-3 px-3 py-2.5">
                    <div className="w-8 h-8 rounded-lg bg-emerald-700 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                        {initials}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold text-slate-300 truncate">{auth?.user_name ?? '—'}</p>
                        <p className="text-xs text-slate-500 truncate">
                            {auth?.role ? (roleBadge[auth.role] ?? auth.role) : '—'}
                        </p>
                    </div>
                </div>
                <button
                    onClick={logout}
                    className="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-sm text-slate-500 hover:bg-red-950/60 hover:text-red-400 transition-all"
                >
                    <IconSignOut />
                    Sign out
                </button>
            </div>
        </>
    );

    return (
        <div className="flex min-h-screen bg-slate-50">
            {/* Mobile overlay */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 z-30 bg-black/50 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar */}
            <aside className={`
                fixed inset-y-0 left-0 z-40 w-60 bg-slate-900 flex flex-col transition-transform duration-200
                lg:translate-x-0
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
            `}>
                <div className="h-16 flex items-center gap-3 px-5 border-b border-slate-800">
                    <div className="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="white" className="w-4 h-4">
                            <path d="M2.879 7.121A3 3 0 0 0 7.5 6.66a2.997 2.997 0 0 0 2.5 1.34 2.997 2.997 0 0 0 2.5-1.34 3 3 0 1 0 4.622-3.78l-.293-.293A2 2 0 0 0 15.415 2H4.585a2 2 0 0 0-1.414.586l-.292.292a3 3 0 0 0 0 4.243ZM3 12v5h5v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h5v-5a3 3 0 0 1-3-3 3 3 0 0 1-2.5 1.338A3 3 0 0 1 9.5 9 3 3 0 0 1 7 10.338 3 3 0 0 1 3 9v3Z" />
                        </svg>
                    </div>
                    <div className="min-w-0">
                        <p className="text-sm font-bold text-white leading-none tracking-tight truncate">
                            {auth?.business_name ?? 'Back Office'}
                        </p>
                        <p className="text-xs text-slate-500 leading-none mt-0.5">Back Office</p>
                    </div>
                </div>
                <NavContent />
            </aside>

            {/* Main */}
            <div className="flex-1 flex flex-col min-w-0 lg:ml-60">
                {/* Mobile topbar */}
                <header className="lg:hidden h-14 bg-white border-b border-slate-200 flex items-center gap-3 px-4 sticky top-0 z-20">
                    <button
                        onClick={() => setSidebarOpen(true)}
                        className="p-2 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" className="w-5 h-5">
                            <path fillRule="evenodd" d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75ZM2 10a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10Zm0 5.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clipRule="evenodd" />
                        </svg>
                    </button>
                    <div className="flex items-center gap-2 min-w-0">
                        <div className="w-6 h-6 rounded-md bg-emerald-600 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="white" className="w-3.5 h-3.5">
                                <path d="M2.879 7.121A3 3 0 0 0 7.5 6.66a2.997 2.997 0 0 0 2.5 1.34 2.997 2.997 0 0 0 2.5-1.34 3 3 0 1 0 4.622-3.78l-.293-.293A2 2 0 0 0 15.415 2H4.585a2 2 0 0 0-1.414.586l-.292.292a3 3 0 0 0 0 4.243ZM3 12v5h5v-3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3h5v-5a3 3 0 0 1-3-3 3 3 0 0 1-2.5 1.338A3 3 0 0 1 9.5 9 3 3 0 0 1 7 10.338 3 3 0 0 1 3 9v3Z" />
                            </svg>
                        </div>
                        <span className="text-sm font-bold text-slate-900 truncate">{auth?.business_name ?? 'Back Office'}</span>
                    </div>
                </header>

                <main className="flex-1 p-4 sm:p-6 lg:p-8">{children}</main>
            </div>
        </div>
    );
}
