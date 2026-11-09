import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import DesktopOnly from '@/components/DesktopOnly';
import { LogoMark } from '@/components/Logo';
import {
    BarChart3,
    Building2,
    ClipboardList,
    FileBarChart,
    LayoutDashboard,
    LogOut,
    Network,
    Phone,
    Settings,
    UserCog,
    Users,
} from 'lucide-react';

type AdminPage =
    | 'overview'
    | 'residents'
    | 'qr-scanner'
    | 'sectors'
    | 'sector-applications'
    | 'certificates'
    | 'staff'
    | 'population'
    | 'audit-log'
    | 'reports'
    | 'org-chart'
    | 'emergency-numbers'
    | 'settings';

type AdminLayoutProps = {
    children: ReactNode;
    active: AdminPage;
};

type NavItem = {
    label: string;
    to: string;
    icon: typeof LayoutDashboard;
    active: boolean;
};

export default function AdminLayout({ children, active }: AdminLayoutProps) {
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
        { label: 'Overview', to: '/admin', icon: LayoutDashboard, active: active === 'overview' },
        { label: 'Residents', to: '/admin/residents', icon: Users, active: active === 'residents' },
        { label: 'Sectors', to: '/admin/sectors', icon: Building2, active: active === 'sectors' || active === 'sector-applications' },
        { label: 'Staff', to: '/admin/staff', icon: UserCog, active: active === 'staff' },
        { label: 'Population', to: '/admin/population', icon: BarChart3, active: active === 'population' },
        { label: 'Audit Log', to: '/admin/audit-log', icon: ClipboardList, active: active === 'audit-log' },
        { label: 'Reports', to: '/admin/reports', icon: FileBarChart, active: active === 'reports' },
        { label: 'Org Chart', to: '/admin/org-chart', icon: Network, active: active === 'org-chart' },
        { label: 'Emergency Numbers', to: '/admin/emergency-numbers', icon: Phone, active: active === 'emergency-numbers' },
        { label: 'Settings', to: '/admin/settings', icon: Settings, active: active === 'settings' },
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
                                Admin / Superuser
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <span className="hidden text-sm text-ink-muted sm:inline">
                            Admin / Superuser
                        </span>
                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-soft text-sm font-semibold text-brand">
                            A
                        </div>
                    </div>
                </header>

                <div className="flex min-h-[calc(100vh-57px)]">
                    <aside className="flex w-60 shrink-0 flex-col border-r border-line-soft bg-surface px-3 py-5">
                        <p className="mb-3 px-3 text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                            Admin Access
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
