import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

type PwdPage = 'dashboard' | 'announcements';

type PwdLayoutProps = {
    children: ReactNode;
    active: PwdPage;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

export default function PwdLayout({
    children,
    active,
    user,
}: PwdLayoutProps) {
    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <div className="min-h-screen bg-[#f7f6f0] text-gray-800">

            {/* TOP HEADER */}
            <header className="flex min-h-14 items-center justify-between bg-[#3f6f3d] px-4 py-3 text-white sm:px-5">

                <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-[#3f6f3d]">
                        SJ
                    </div>

                    <span className="text-sm font-semibold">
                        Barangay San Jose <span className="font-normal opacity-80">| PWD Sector Portal</span>
                    </span>
                </div>

                <div className="flex items-center gap-3">
                    <span className="hidden text-sm sm:inline">
                        {user?.name || 'PWD Staff'} ({user?.staff_id || 'PWD-001'})
                    </span>

                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-[#315b30] font-semibold">
                        {user?.name ? user.name.charAt(0).toUpperCase() : 'P'}
                    </div>
                </div>

            </header>

            <div className="flex flex-col lg:flex-row">

                {/* SIDEBAR */}
                <aside className="flex min-h-0 w-full flex-col border-b border-gray-200 bg-white px-3 py-4 lg:min-h-[calc(100vh-56px)] lg:w-52 lg:border-b-0 lg:border-r lg:py-5">

                    <p className="mb-3 px-3 text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                        PWD Sector Access
                    </p>

                    {/* MAIN NAVIGATION */}
                    <nav className="grid grid-cols-2 gap-1 lg:block lg:space-y-1">

                        <SidebarLink
                            href="/pwd/dashboard"
                            icon="📊"
                            label="Dashboard"
                            active={active === 'dashboard'}
                        />

                        <SidebarLink
                            href="/pwd/announcements"
                            icon="📢"
                            label="Announcements"
                            active={active === 'announcements'}
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