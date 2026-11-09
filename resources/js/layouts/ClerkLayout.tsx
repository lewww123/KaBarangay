import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import DesktopOnly from '@/components/DesktopOnly';
import { LogoMark } from '@/components/Logo';
import {
    Banknote,
    BookOpen,
    CalendarDays,
    FileText,
    LayoutDashboard,
    LogOut,
    Megaphone,
    Network,
} from 'lucide-react';

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

type NavItem = {
    label: string;
    to: string;
    icon: typeof LayoutDashboard;
    active: boolean;
};

export default function ClerkLayout({ children, active, user }: ClerkLayoutProps) {
    const handleLogout = () => {
        router.post('/logout');
    };
    const { auth } = usePage<{
        auth?: { activeBarangay?: { id: number; name: string } | null };
    }>().props;
    const barangayLabel = auth?.activeBarangay?.name
        ? `Barangay ${auth.activeBarangay.name}`
        : 'Barangay';

    const navItems: NavItem[] = [
        { label: 'Overview', to: '/clerk/dashboard', icon: LayoutDashboard, active: active === 'overview' },
        { label: 'Certificates', to: '/clerk/certificates', icon: FileText, active: active === 'certificates' },
        { label: 'Updates', to: '/clerk/updates', icon: Megaphone, active: active === 'updates' },
        { label: 'Programs', to: '/clerk/programs', icon: CalendarDays, active: active === 'programs' },
        { label: 'Disbursements', to: '/clerk/disbursements', icon: Banknote, active: active === 'disbursements' },
        { label: "Citizen's Charter", to: '/clerk/citizen-charter', icon: BookOpen, active: active === 'citizen-charter' },
        { label: 'Org Chart', to: '/clerk/org-chart', icon: Network, active: active === 'org-chart' },
    ];

    return (
        <div className="min-h-screen bg-canvas text-ink">
            <div className="hidden lg:block">
                <header className="flex min-h-14 items-center justify-between border-b border-line-soft bg-surface px-5 py-3">
                    <div className="flex items-center gap-3">
                        <LogoMark tone="onBrand" size="sm" />
                        <div className="leading-tight">
                            <p className="text-sm font-semibold text-ink-strong">
                                {barangayLabel}
                            </p>
                            <p className="text-xs text-ink-muted">
                                Clerk Portal
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <span className="hidden text-sm text-ink-muted sm:inline">
                            {user?.name || 'Clerk Staff'} ({user?.staff_id || 'CLERK-001'})
                        </span>
                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-soft text-sm font-semibold text-brand">
                            {(user?.name || 'C').charAt(0).toUpperCase()}
                        </div>
                    </div>
                </header>

                <div className="flex min-h-[calc(100vh-57px)]">
                    <aside className="flex w-60 shrink-0 flex-col border-r border-line-soft bg-surface px-3 py-5">
                        <p className="mb-3 px-3 text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                            Clerk Access
                        </p>

                        <nav className="space-y-1">
                            {navItems.map((item) => (
                                <SidebarLink key={item.to} {...item} />
                            ))}
                        </nav>

                        <div className="mt-auto border-t border-line-soft pt-4">
                            <button
                                type="button"
                                onClick={handleLogout}
                                className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-red-600 transition hover:bg-danger-soft"
                            >
                                <LogOut className="size-4" />
                                <span>Logout</span>
                            </button>
                        </div>
                    </aside>

                    <main className="min-w-0 flex-1 p-4 sm:p-6 lg:p-7">
                        {children}
                    </main>
                </div>
            </div>

            <div className="lg:hidden">
                <DesktopOnly />
            </div>
        </div>
    );
}

function SidebarLink({ label, to, icon: Icon, active }: NavItem) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={to}
            className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                active
                    ? 'bg-brand-soft text-brand'
                    : 'text-ink-muted hover:bg-canvas hover:text-ink'
            }`}
        >
            <Icon className="size-4 shrink-0" />
            <span>{label}</span>
        </Link>
    );
}
