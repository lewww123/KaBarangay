import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

type LuponPage = 'dashboard' | 'complaints' | 'cases' | 'hearings';

type LuponLayoutProps = {
    children: ReactNode;
    active: LuponPage;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
    barangayName?: string | null;
};

export default function LuponLayout({
    children,
    active,
    user,
    barangayName,
}: LuponLayoutProps) {
    const handleLogout = () => {
        router.post('/logout');
    };

    const brand = barangayName || 'Barangay';

    return (
        <div className="min-h-screen bg-[#f7f6f0] text-gray-800">
            {/* TOP HEADER */}
            <header className="flex min-h-14 items-center justify-between bg-[#3f6f3d] px-4 py-3 text-white sm:px-5">
                <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-[#3f6f3d]">
                        {brand.charAt(0).toUpperCase()}
                    </div>

                    <span className="text-sm font-semibold">
                        {brand}{' '}
                        <span className="font-normal opacity-80">
                            | Lupon Portal
                        </span>
                    </span>
                </div>

                <div className="flex items-center gap-3">
                    <span className="hidden text-sm sm:inline">
                        {user?.name || 'Lupon Staff'} (
                        {user?.staff_id || 'LUP-001'})
                    </span>

                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[#315b30] font-semibold">
                        {user?.name ? user.name.charAt(0).toUpperCase() : 'L'}
                    </div>
                </div>
            </header>

            <div className="flex flex-col lg:flex-row">
                {/* SIDEBAR */}
                <aside className="flex min-h-0 w-full flex-col border-b border-gray-200 bg-white px-3 py-4 lg:min-h-[calc(100vh-56px)] lg:w-52 lg:border-r lg:border-b-0 lg:py-5">
                    <p className="mb-3 px-3 text-[10px] font-semibold tracking-wide text-gray-400 uppercase">
                        Lupon Desk Access
                    </p>

                    {/* MAIN NAVIGATION */}
                    <nav className="grid grid-cols-2 gap-1 lg:block lg:space-y-1">
                        <SidebarLink
                            href="/lupon/dashboard"
                            icon="⚖️"
                            label="Dashboard"
                            active={active === 'dashboard'}
                        />

                        <SidebarLink
                            href="/lupon/complaints"
                            icon="📋"
                            label="Complaints"
                            active={active === 'complaints'}
                        />

                        <SidebarLink
                            href="/lupon/cases"
                            icon="📂"
                            label="Cases"
                            active={active === 'cases'}
                        />

                        <SidebarLink
                            href="/lupon/hearings"
                            icon="🗓️"
                            label="Hearings"
                            active={active === 'hearings'}
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
                    ? 'bg-[#e8f1e4] text-[#3f6f3d]'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800'
            }`}
        >
            <span className="w-4 text-center">{icon}</span>
            <span>{label}</span>
        </Link>
    );
}
