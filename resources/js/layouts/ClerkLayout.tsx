import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

type ClerkPage =
    | 'overview'
    | 'certificates'
    | 'announcements'
    | 'updates'
    | 'programs'
    | 'disbursements'
    | 'citizen-charter'
    | 'org-chart';

type ClerkLayoutProps = {
    children: ReactNode;
    active: ClerkPage;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

export default function ClerkLayout({
    children,
    active,
    user,
}: ClerkLayoutProps) {
    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <div className="min-h-screen bg-[#eef1f5] text-gray-800">
            {/* TOP HEADER */}
            <header className="flex min-h-14 items-center justify-between bg-[#3b6fa8] px-4 py-3 text-white sm:px-5">
                <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-[#3b6fa8]">
                        SJ
                    </div>

                    <span className="text-sm font-semibold">
                        Barangay San Jose{' '}
                        <span className="font-normal opacity-80">
                            | Clerk Portal
                        </span>
                    </span>
                </div>

                <div className="flex items-center gap-3">
                    <span className="hidden text-sm sm:inline">
                        {user?.name || 'Clerk Staff'} (
                        {user?.staff_id || 'CLERK-001'})
                    </span>

                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[#2a5279] font-semibold">
                        {user?.name ? user.name.charAt(0).toUpperCase() : 'C'}
                    </div>
                </div>
            </header>

            <div className="flex flex-col lg:flex-row">
                {/* SIDEBAR */}
                <aside className="flex min-h-0 w-full flex-col border-b border-gray-200 bg-white px-3 py-4 lg:min-h-[calc(100vh-56px)] lg:w-52 lg:border-r lg:border-b-0 lg:py-5">
                    <p className="mb-3 px-3 text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                        Clerk Access
                    </p>

                    {/* MAIN NAVIGATION */}
                    <nav className="grid grid-cols-2 gap-1 sm:grid-cols-3 lg:block lg:space-y-1">
                        <SidebarLink
                            href="/clerk/dashboard"
                            icon="🏠"
                            label="Overview"
                            active={active === 'overview'}
                        />

                        <SidebarLink
                            href="/clerk/certificates"
                            icon="📜"
                            label="Certificates"
                            active={active === 'certificates'}
                        />

                        <SidebarLink
                            href="/clerk/updates"
                            icon="U"
                            label="Updates"
                            active={active === 'updates'}
                        />

                        <SidebarLink
                            href="/clerk/programs"
                            icon="P"
                            label="Programs"
                            active={active === 'programs'}
                        />

                        <SidebarLink
                            href="/clerk/disbursements"
                            icon="💰"
                            label="Disbursements"
                            active={active === 'disbursements'}
                        />

                        <SidebarLink
                            href="/clerk/citizen-charter"
                            icon="📋"
                            label="Citizen's Charter"
                            active={active === 'citizen-charter'}
                        />

                        <SidebarLink
                            href="/clerk/org-chart"
                            icon="▥"
                            label="Org Chart"
                            active={active === 'org-chart'}
                        />
                    </nav>

                    {/* LOGOUT */}
                    <div className="mt-4 border-t border-gray-200 pt-4 lg:mt-auto">
                        <button
                            type="button"
                            onClick={handleLogout}
                            className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-red-50"
                        >
                            <span className="w-4 text-center">↪</span>
                            <span>Logout</span>
                        </button>
                    </div>
                </aside>

                {/* PAGE CONTENT */}
                <main className="min-w-0 flex-1 p-4 sm:p-6 lg:p-7">
                    {children}
                </main>
            </div>
        </div>
    );
}

function SidebarLink({
    href,
    icon,
    label,
    active = false,
}: {
    href: string;
    icon: string;
    label: string;
    active?: boolean;
}) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={href}
            className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition ${
                active
                    ? 'bg-[#eaf1f8] text-[#3b6fa8]'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800'
            }`}
        >
            <span className="w-4 text-center">{icon}</span>
            <span>{label}</span>
        </Link>
    );
}
