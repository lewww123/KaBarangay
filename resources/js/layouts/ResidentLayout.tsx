import KaBarangayAssistant from '@/components/resident/KaBarangayAssistant';
import NotificationBell from '@/components/resident/NotificationBell';
import { LogoMark } from '@/components/Logo';
import {
    applyResidentSettings,
    readResidentSettings,
    RESIDENT_SETTINGS_EVENT,
} from '@/lib/residentSettings';
import type { ResidentSettings } from '@/lib/residentSettings';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    BookOpen,
    CalendarDays,
    ChevronRight,
    ClipboardCheck,
    FileText,
    Gavel,
    HeartHandshake,
    History,
    Home,
    LogOut,
    Megaphone,
    MessageSquare,
    MoreHorizontal,
    Network,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { ReactNode, useEffect, useMemo, useState } from 'react';

type ResidentPage =
    | 'home'
    | 'certificates'
    | 'lupon'
    | 'sectors'
    | 'announcements'
    | 'updates'
    | 'programs'
    | 'disbursement'
    | 'charter'
    | 'org-chart'
    | 'chatbot'
    | 'profile'
    | 'settings'
    | 'about';

type ResidentLayoutProps = {
    children: ReactNode;
    active?: ResidentPage;
};

type NavItem = {
    label: string;
    to: string;
    icon: LucideIcon;
};

type NavGroup = {
    label: string;
    items: NavItem[];
};

/**
 * The mobile dock. Five destinations, matching the resident portal preview.
 */
const residentNav: NavItem[] = [
    { label: 'Home', to: '/resident', icon: Home },
    { label: 'Certificates', to: '/resident/certificates', icon: FileText },
    { label: 'Announcements', to: '/resident/announcements', icon: Megaphone },
    { label: 'Lupon', to: '/resident/lupon', icon: HeartHandshake },
    { label: 'Profile', to: '/resident/profile', icon: Users },
];

/**
 * The desktop sidebar and the mobile "More" sheet share the same groups.
 */
const residentMoreNav: NavGroup[] = [
    {
        label: 'Main Services',
        items: [
            { label: 'Home', to: '/resident', icon: Home },
            { label: 'Request Certificate', to: '/resident/certificates', icon: FileText },
            { label: 'File a Complaint', to: '/resident/lupon', icon: Gavel },
            { label: 'Sector Services', to: '/resident/sector-applications', icon: ClipboardCheck },
            { label: 'Announcements', to: '/resident/announcements', icon: Megaphone },
            { label: 'Updates', to: '/resident/updates', icon: History },
            { label: 'Programs', to: '/resident/programs', icon: CalendarDays },
            { label: 'Disbursement', to: '/resident/disbursement', icon: Banknote },
        ],
    },
    {
        label: 'Information',
        items: [
            { label: "Citizen's Charter", to: '/resident/citizen-charter', icon: BookOpen },
            { label: 'Organizational Chart', to: '/resident/org-chart', icon: Network },
            { label: 'Kabarangay AI', to: '/resident/chatbot', icon: MessageSquare },
        ],
    },
    {
        label: 'Account',
        items: [
            { label: 'My Profile', to: '/resident/profile', icon: Users },
            { label: 'Settings', to: '/resident/settings', icon: Settings },
            { label: 'About', to: '/resident/about', icon: ShieldCheck },
        ],
    },
];

type SharedResident = {
    first_name?: string | null;
    last_name?: string | null;
    full_name?: string | null;
    barangay?: { name?: string | null } | null;
};

export default function ResidentLayout({
    children,
    active = 'home',
}: ResidentLayoutProps) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const { url, props } = usePage<{ resident?: SharedResident }>();
    const resident = props.resident;

    const residentName =
        resident?.full_name ||
        [resident?.first_name, resident?.last_name].filter(Boolean).join(' ') ||
        'Resident';

    const barangayName = resident?.barangay?.name ?? 'Barangay San Jose';

    const initials = useMemo(() => {
        const parts = residentName.split(/\s+/).filter(Boolean);

        if (parts.length === 0) {
            return 'RS';
        }

        return `${parts[0][0] ?? ''}${parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : ''}`.toUpperCase();
    }, [residentName]);

    const pathname = useMemo(() => url.split('?')[0], [url]);

    const pageLabel = useMemo(() => {
        const item = residentMoreNav
            .flatMap((group) => group.items)
            .filter((entry) =>
                entry.to === '/resident'
                    ? pathname === '/resident'
                    : pathname === entry.to || pathname.startsWith(`${entry.to}/`),
            )
            .sort((a, b) => b.to.length - a.to.length)[0];

        return item?.label ?? 'Home';
    }, [pathname]);

    /*
    |--------------------------------------------------------------------------
    | Apply Resident Appearance and Accessibility Settings
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');

        const applyCurrentSettings = () => {
            applyResidentSettings(readResidentSettings(), colorScheme.matches);
        };

        const handleSettingsEvent = (event: Event) => {
            const detail =
                event instanceof CustomEvent
                    ? (event.detail as ResidentSettings | undefined)
                    : undefined;

            applyResidentSettings(
                detail ?? readResidentSettings(),
                colorScheme.matches,
            );
        };

        const handleColorSchemeChange = () => {
            applyCurrentSettings();
        };

        applyCurrentSettings();

        window.addEventListener(RESIDENT_SETTINGS_EVENT, handleSettingsEvent);
        window.addEventListener('storage', handleSettingsEvent);

        colorScheme.addEventListener('change', handleColorSchemeChange);

        return () => {
            window.removeEventListener(
                RESIDENT_SETTINGS_EVENT,
                handleSettingsEvent,
            );
            window.removeEventListener('storage', handleSettingsEvent);

            colorScheme.removeEventListener('change', handleColorSchemeChange);
        };
    }, []);

    /*
    |--------------------------------------------------------------------------
    | Mobile Sheet Behaviour
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        if (mobileMenuOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }

        return () => {
            document.body.style.overflow = '';
        };
    }, [mobileMenuOpen]);

    useEffect(() => {
        setMobileMenuOpen(false);
    }, [pathname]);

    useEffect(() => {
        if (!mobileMenuOpen) {
            return;
        }

        const handleKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMobileMenuOpen(false);
            }
        };

        window.addEventListener('keydown', handleKey);

        return () => window.removeEventListener('keydown', handleKey);
    }, [mobileMenuOpen]);

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    const handleLogout = () => {
        const confirmed = window.confirm('Are you sure you want to log out?');

        if (!confirmed) {
            return;
        }

        router.post('/resident/logout');
    };

    return (
        <div className="min-h-screen bg-shell">
            <div className="mx-auto flex min-h-screen w-full bg-canvas md:max-w-none md:flex-row">
                {/* ========================================================
                    DESKTOP SIDEBAR
                ======================================================== */}

                <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col self-start border-r border-line-soft bg-surface md:flex">
                    <div className="flex h-16 shrink-0 items-center gap-3 border-b border-line-soft px-5">
                        <LogoMark size="sm" />
                        <div className="min-w-0 leading-tight">
                            <p className="truncate text-[13px] font-semibold text-ink-strong">
                                {barangayName}
                            </p>
                            <p className="truncate text-[11px] text-ink-muted">
                                Resident Portal
                            </p>
                        </div>
                    </div>

                    <nav className="scrollbar-slim flex-1 space-y-4 overflow-y-auto px-3 py-5">
                        {residentMoreNav.map((group) => (
                            <div key={group.label}>
                                <p className="mb-1.5 px-3 text-[10px] font-semibold tracking-wider text-ink-faint uppercase">
                                    {group.label}
                                </p>
                                <div className="space-y-1">
                                    {group.items.map((item) => (
                                        <SidebarLinkRow
                                            key={`${group.label}-${item.to}`}
                                            item={item}
                                            pathname={pathname}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>

                    <div className="shrink-0 border-t border-line-soft p-3">
                        <Link
                            href="/resident/profile"
                            className="flex items-center gap-3 rounded-xl bg-canvas px-3 py-2.5 transition-colors hover:bg-brand-soft"
                        >
                            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-xs font-semibold text-white">
                                {initials}
                            </span>
                            <div className="min-w-0 flex-1 leading-tight">
                                <p className="truncate text-[13px] font-medium text-ink-strong">
                                    {residentName}
                                </p>
                                <p className="truncate text-[11px] text-ink-muted">
                                    Resident
                                </p>
                            </div>
                            <ChevronRight className="size-4 shrink-0 text-ink-faint" />
                        </Link>

                        <button
                            type="button"
                            onClick={handleLogout}
                            className="mt-1 flex min-h-9 w-full items-center gap-2 rounded-lg px-3 text-xs font-medium text-ink-muted transition-colors hover:bg-danger-soft hover:text-red-700"
                        >
                            <LogOut className="size-4" />
                            Logout
                        </button>
                    </div>
                </aside>

                <div className="flex min-h-screen min-w-0 flex-1 flex-col">
                    {/* ====================================================
                        MOBILE APP BAR
                    ==================================================== */}

                    <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center justify-between gap-3 border-b border-line-soft bg-surface px-4 md:hidden">
                        <div className="flex min-w-0 items-center gap-2.5">
                            <LogoMark size="sm" />
                            <div className="min-w-0 leading-tight">
                                <p className="truncate text-[13px] font-semibold text-ink-strong">
                                    {barangayName}
                                </p>
                                <p className="truncate text-[11px] text-ink-muted">
                                    Resident Portal
                                </p>
                            </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                aria-label="More"
                                onClick={() => setMobileMenuOpen(true)}
                                className="grid size-9 place-items-center rounded-lg text-ink-muted transition-colors hover:bg-canvas"
                            >
                                <MoreHorizontal className="size-5" />
                            </button>

                            <NotificationBell />

                            <Link
                                href="/resident/profile"
                                className="ml-1 grid size-8 place-items-center rounded-full bg-brand text-[11px] font-semibold text-white"
                            >
                                {initials}
                            </Link>
                        </div>
                    </header>

                    {/* ====================================================
                        DESKTOP TOP BAR
                    ==================================================== */}

                    <header className="sticky top-0 z-30 hidden h-16 shrink-0 items-center gap-4 border-b border-line-soft bg-surface px-6 md:flex xl:px-8">
                        <div className="min-w-0">
                            <p className="text-[10px] font-semibold tracking-wider text-ink-faint uppercase">
                                Resident Portal
                            </p>
                            <p className="truncate text-sm font-semibold text-ink-strong">
                                {pageLabel}
                            </p>
                        </div>

                        <div className="ml-auto flex items-center gap-3">
                            <NotificationBell />
                            <span className="hidden h-6 w-px bg-line-soft sm:block" />
                            <div className="hidden text-right leading-tight sm:block">
                                <p className="text-[13px] font-medium text-ink-strong">
                                    {residentName}
                                </p>
                                <p className="text-[11px] text-ink-muted">
                                    Resident
                                </p>
                            </div>
                            <Link
                                href="/resident/profile"
                                className="grid size-9 place-items-center rounded-full bg-brand text-xs font-semibold text-white"
                            >
                                {initials}
                            </Link>
                        </div>
                    </header>

                    {/* ====================================================
                        CONTENT
                    ==================================================== */}

                    <main className="flex-1 px-4 pb-28 pt-4 md:px-6 md:pb-10 md:pt-6 xl:px-8">
                        <div className="md:mx-auto md:max-w-6xl">{children}</div>
                    </main>

                    {/* ====================================================
                        MOBILE BOTTOM NAVIGATION
                    ==================================================== */}

                    <nav className="safe-bottom fixed right-0 bottom-0 left-0 z-40 border-t border-line-soft bg-surface/95 backdrop-blur md:hidden">
                        <ul className="grid grid-cols-5">
                            {residentNav.map((item) => (
                                <NavItemRow
                                    key={item.to}
                                    item={item}
                                    pathname={pathname}
                                />
                            ))}
                        </ul>
                    </nav>
                </div>
            </div>

            {/* ============================================================
                MOBILE "MORE" SHEET
            ============================================================ */}

            {mobileMenuOpen && (
                <div className="fixed inset-0 z-50 md:hidden">
                    <button
                        type="button"
                        aria-label="Close navigation menu"
                        onClick={() => setMobileMenuOpen(false)}
                        className="absolute inset-0 bg-black/40"
                    />

                    <section className="absolute right-0 bottom-0 left-0 mx-auto flex max-h-[75vh] w-full max-w-lg flex-col rounded-t-2xl border border-line-soft bg-surface p-4 shadow-raised">
                        <div className="flex items-center justify-between pb-1">
                            <h2 className="text-base font-semibold text-ink-strong">
                                More
                            </h2>
                            <button
                                type="button"
                                onClick={() => setMobileMenuOpen(false)}
                                className="grid size-9 place-items-center rounded-lg text-ink-muted transition-colors hover:bg-canvas"
                                aria-label="Close navigation menu"
                            >
                                <ChevronRight className="size-5 -rotate-90" />
                            </button>
                        </div>

                        <div className="-mx-1 overflow-y-auto px-1 pb-1">
                            {residentMoreNav.map((group) => (
                                <section
                                    key={group.label}
                                    className="mt-3 first:mt-1"
                                >
                                    <p className="px-2 pb-1 text-[11px] font-semibold tracking-wider text-ink-faint uppercase">
                                        {group.label}
                                    </p>
                                    <ul className="space-y-0.5">
                                        {group.items.map((item) => (
                                            <MoreLinkRow
                                                key={item.to}
                                                item={item}
                                                pathname={pathname}
                                            />
                                        ))}
                                    </ul>
                                </section>
                            ))}

                            <button
                                type="button"
                                onClick={handleLogout}
                                className="mt-3 flex min-h-11 w-full items-center gap-3 rounded-lg px-2 text-sm font-medium text-red-600 transition-colors hover:bg-danger-soft"
                            >
                                <LogOut className="size-5 shrink-0" />
                                Logout
                            </button>
                        </div>
                    </section>
                </div>
            )}

            {/* ============================================================
                KABARANGAY ASSISTANT
            ============================================================ */}

            {active !== 'chatbot' && <KaBarangayAssistant />}
        </div>
    );
}

function isActivePath(item: NavItem, pathname: string) {
    return item.to === '/resident'
        ? pathname === '/resident'
        : pathname === item.to || pathname.startsWith(`${item.to}/`);
}

function SidebarLinkRow({
    item,
    pathname,
}: {
    item: NavItem;
    pathname: string;
}) {
    const Icon = item.icon;
    const isActive = isActivePath(item, pathname);

    return (
        <Link
            href={item.to}
            className={
                isActive
                    ? 'flex min-h-10 items-center gap-3 rounded-lg bg-brand-soft px-3 text-sm font-medium text-brand-deep'
                    : 'flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-canvas hover:text-ink-strong'
            }
        >
            <Icon className="size-[18px] shrink-0" strokeWidth={1.9} />
            <span className="truncate">{item.label}</span>
        </Link>
    );
}

function NavItemRow({
    item,
    pathname,
}: {
    item: NavItem;
    pathname: string;
}) {
    const Icon = item.icon;
    const isActive = isActivePath(item, pathname);

    return (
        <li>
            <Link
                href={item.to}
                className={`relative flex flex-col items-center gap-1 py-2.5 text-[10px] font-medium transition-colors ${
                    isActive ? 'text-brand' : 'text-ink-muted'
                }`}
            >
                <span
                    className={`absolute -top-px h-0.5 w-8 rounded-full transition-colors ${
                        isActive ? 'bg-brand' : 'bg-transparent'
                    }`}
                />
                <Icon
                    className="size-5"
                    strokeWidth={isActive ? 2.2 : 1.8}
                />
                <span className="truncate px-0.5">{item.label}</span>
            </Link>
        </li>
    );
}

function MoreLinkRow({
    item,
    pathname,
}: {
    item: NavItem;
    pathname: string;
}) {
    const Icon = item.icon;
    const isActive = isActivePath(item, pathname);

    return (
        <li>
            <Link
                href={item.to}
                className={
                    isActive
                        ? 'flex min-h-11 items-center gap-3 rounded-lg bg-brand-soft px-2 text-sm font-medium text-brand-deep'
                        : 'flex min-h-11 items-center gap-3 rounded-lg px-2 text-sm font-medium text-ink transition-colors hover:bg-canvas'
                }
            >
                <Icon
                    className="size-5 shrink-0"
                    strokeWidth={isActive ? 2.2 : 1.8}
                />
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
                <ChevronRight className="size-4 shrink-0 text-ink-faint" />
            </Link>
        </li>
    );
}
