import { Link, router } from '@inertiajs/react';
import {
    Bell,
    ChevronsLeft,
    ChevronsRight,
    LogOut,
    Search,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    createContext,
    useContext,
    useState,
    type ReactNode,
} from 'react';

import DesktopOnly from '@/components/DesktopOnly';
import { Logo } from '@/components/Logo';
import { cn } from '@/lib/utils';

type SidebarState = { collapsed: boolean };

const SidebarContext = createContext<SidebarState>({ collapsed: false });

export type DeskUser = {
    name: string;
    role: string;
    initials?: string;
};

type DeskLayoutProps = {
    children: ReactNode;
    /** Small label above the navigation, e.g. "Admin Portal". */
    portalLabel: string;
    user: DeskUser;
    /** Navigation groups built from DeskNavGroup/DeskNavLink. */
    nav: ReactNode;
    searchPlaceholder?: string;
};

function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

/**
 * Shared desk shell for the admin, clerk, lupon, PWD and senior portals.
 *
 * It mirrors the reference dashboard layout: full-height brand-blue sidebar,
 * white top bar with search and account card, and a soft canvas content area.
 * Below `lg` these portals stay desktop-only.
 */
export default function DeskLayout({
    children,
    portalLabel,
    user,
    nav,
    searchPlaceholder = 'Search',
}: DeskLayoutProps) {
    const [collapsed, setCollapsed] = useState(false);
    const initials = user.initials || initialsOf(user.name) || 'K';
    const handleLogout = () => {
        router.post('/logout');
    };

    const sidebar = (
        <aside
            className={cn(
                'fixed inset-y-0 left-0 z-40 hidden flex-col bg-brand transition-[width] duration-200 lg:flex',
                collapsed ? 'w-[76px]' : 'w-64',
            )}
        >
            <div
                className={cn(
                    'relative flex h-16 shrink-0 items-center border-b border-white/15',
                    collapsed ? 'justify-center px-3' : 'justify-between px-4',
                )}
            >
                <Logo
                    tone="onBrand"
                    collapsed={collapsed}
                    subtitle="Management System"
                />
                {!collapsed && (
                    <button
                        type="button"
                        onClick={() => setCollapsed(true)}
                        aria-label="Collapse sidebar"
                        className="grid size-8 place-items-center rounded-md text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                    >
                        <ChevronsLeft className="size-4" />
                    </button>
                )}
                {collapsed && (
                    <button
                        type="button"
                        onClick={() => setCollapsed(false)}
                        aria-label="Expand sidebar"
                        className="absolute -right-3 top-5 grid size-6 place-items-center rounded-full border border-line-soft bg-surface text-ink-muted shadow-card transition-colors hover:text-brand"
                    >
                        <ChevronsRight className="size-3.5" />
                    </button>
                )}
            </div>

            <nav className="scrollbar-slim flex-1 overflow-y-auto px-3 py-4">
                {portalLabel && !collapsed && (
                    <p className="mb-3 px-1 text-[11px] font-semibold tracking-wider text-white/50 uppercase">
                        {portalLabel}
                    </p>
                )}
                <div className="flex flex-col gap-1">
                    <SidebarContext.Provider value={{ collapsed }}>
                        {nav}
                    </SidebarContext.Provider>
                </div>
            </nav>

            <div
                className={cn(
                    'shrink-0 border-t border-white/15 p-3',
                    collapsed && 'px-2',
                )}
            >
                <div
                    className={cn(
                        'flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2.5',
                        collapsed && 'justify-center px-0',
                    )}
                >
                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-white text-xs font-semibold text-brand">
                        {initials}
                    </span>
                    {!collapsed && (
                        <div className="min-w-0 flex-1 leading-tight">
                            <p className="truncate text-[13px] font-medium text-white">
                                {user.name}
                            </p>
                            <p className="truncate text-[11px] text-white/65">
                                {user.role}
                            </p>
                        </div>
                    )}
                </div>
                <button
                    type="button"
                    onClick={handleLogout}
                    title={collapsed ? 'Log out' : undefined}
                    className={cn(
                        'mt-2 flex h-9 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-white/75 transition-colors hover:bg-white/10 hover:text-white',
                        collapsed && 'justify-center px-0',
                    )}
                >
                    <LogOut className="size-[18px] shrink-0" />
                    {!collapsed && <span>Log out</span>}
                </button>
            </div>
        </aside>
    );

    return (
        <>
            <div className="hidden min-h-screen bg-canvas lg:flex">
                {sidebar}
                <div
                    className={cn(
                        'flex min-h-screen flex-1 flex-col transition-[padding] duration-200',
                        collapsed ? 'pl-[76px]' : 'pl-64',
                    )}
                >
                    <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-4 border-b border-line-soft bg-surface px-6">
                        <div className="relative w-full max-w-md">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-faint" />
                            <input
                                type="search"
                                placeholder={searchPlaceholder}
                                className="h-9 w-full rounded-lg border border-line-soft bg-canvas pr-3 pl-9 text-sm text-ink transition placeholder:text-ink-faint focus:border-brand focus:bg-surface focus:ring-2 focus:ring-brand/20 focus:outline-none"
                            />
                        </div>

                        <div className="ml-auto flex items-center gap-3">
                            <button
                                type="button"
                                aria-label="Notifications"
                                className="relative grid size-9 place-items-center rounded-lg text-ink-muted transition-colors hover:bg-canvas hover:text-brand"
                            >
                                <Bell className="size-[18px]" />
                                <span className="absolute top-2 right-2 size-1.5 rounded-full bg-brand" />
                            </button>
                            <div className="hidden text-right leading-tight xl:block">
                                <p className="text-[13px] font-medium text-ink-strong">
                                    {user.name}
                                </p>
                                <p className="text-[11px] text-ink-muted">
                                    {user.role}
                                </p>
                            </div>
                            <span className="grid size-9 place-items-center rounded-full bg-brand text-xs font-semibold text-white">
                                {initials}
                            </span>
                        </div>
                    </header>

                    <main className="flex-1 px-6 py-6 xl:px-8">
                        {children}
                    </main>
                </div>
            </div>

            {/* Below `lg` the desk portals are intentionally desktop-only. */}
            <div className="lg:hidden">
                <DesktopOnly />
            </div>
        </>
    );
}

export function useDeskSidebar(): SidebarState {
    return useContext(SidebarContext);
}

export function DeskNavGroup({
    label,
    children,
}: {
    label?: string;
    children: ReactNode;
}) {
    const { collapsed } = useContext(SidebarContext);

    return (
        <div className="mb-4 last:mb-0">
            {label && !collapsed && (
                <p className="mb-1.5 px-3 text-[11px] font-semibold tracking-wider text-white/50 uppercase">
                    {label}
                </p>
            )}
            <div className="flex flex-col gap-1">{children}</div>
        </div>
    );
}

export function DeskNavLink({
    href,
    icon: Icon,
    label,
    active = false,
    className,
}: {
    href: string;
    icon: LucideIcon;
    label: string;
    active?: boolean;
    className?: string;
}) {
    const { collapsed } = useContext(SidebarContext);

    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={href}
            title={collapsed ? label : undefined}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors',
                collapsed && 'justify-center px-0',
                active
                    ? 'bg-white text-brand'
                    : 'text-white/75 hover:bg-white/10 hover:text-white',
                className,
            )}
        >
            <Icon className="size-[18px] shrink-0" />
            {!collapsed && <span className="truncate">{label}</span>}
        </Link>
    );
}

export function DeskSubNavLink({
    href,
    label,
    active = false,
}: {
    href: string;
    label: string;
    active?: boolean;
}) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex items-center gap-2 rounded-lg py-2 pr-3 pl-9 text-sm font-medium transition-colors',
                active
                    ? 'bg-white/15 text-white'
                    : 'text-white/65 hover:bg-white/10 hover:text-white',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    active ? 'bg-white' : 'bg-white/40',
                )}
            />
            <span>{label}</span>
        </Link>
    );
}
