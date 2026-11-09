import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import DesktopOnly from '@/components/DesktopOnly';
import { LogoMark } from '@/components/Logo';
import {
    ChevronDown,
    FolderOpen,
    LayoutDashboard,
    LogOut,
    MessageSquare,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type LegacyLuponPage =
    | 'dashboard'
    | 'payments'
    | 'hearings'
    | 'pangkat'
    | 'arbitration'
    | 'documents'
    | 'records'
    | 'reports'
    | 'profile';

export type LuponCaseStage =
    | 'all'
    | 'mediation'
    | 'conciliation'
    | 'arbitration'
    | 'resolved';

type LuponPage =
    | 'overview'
    | 'complaints'
    | 'cases'
    | 'tagapamayapa'
    | LegacyLuponPage;

type LuponLayoutProps = {
    children: ReactNode;
    active: LuponPage;
    caseStage?: LuponCaseStage;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
    barangayName?: string | null;
};

const caseNavigation: {
    id: LuponCaseStage;
    href: string;
    label: string;
}[] = [
    { id: 'all', href: '/lupon/cases', label: 'All Cases' },
    { id: 'mediation', href: '/lupon/cases?stage=mediation', label: 'Mediation' },
    { id: 'conciliation', href: '/lupon/cases?stage=conciliation', label: 'Conciliation' },
    { id: 'arbitration', href: '/lupon/cases?stage=arbitration', label: 'Arbitration' },
    { id: 'resolved', href: '/lupon/cases?stage=resolved', label: 'Resolved' },
];

export default function LuponLayout({
    children,
    active,
    caseStage,
    user,
    barangayName,
}: LuponLayoutProps) {
    const handleLogout = () => {
        router.post('/logout');
    };

    const shared = usePage<{ auth?: { user?: { name?: string } } }>().props;
    user = user ?? shared.auth?.user;
    const brand = barangayName || 'Barangay';
    const casesActive = active === 'cases';
    const [casesOpen, setCasesOpen] = useState(casesActive);
    const activeStage = caseStage ?? 'all';

    useEffect(() => {
        if (casesActive) {
            setCasesOpen(true);
        }
    }, [casesActive]);

    return (
        <div className="min-h-screen bg-canvas text-ink">
            <div className="hidden lg:block">
                <header className="flex min-h-14 items-center justify-between border-b border-line-soft bg-surface px-5 py-3">
                    <div className="flex items-center gap-3">
                        <LogoMark tone="onBrand" size="sm" />
                        <div className="leading-tight">
                            <p className="text-sm font-semibold text-ink-strong">{brand}</p>
                            <p className="text-xs text-ink-muted">Lupon Portal</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <span className="hidden text-sm text-ink-muted sm:inline">
                            {user?.name || 'Lupon Staff'}
                            {user?.staff_id ? ` (${user.staff_id})` : ''}
                        </span>
                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-soft text-sm font-semibold text-brand">
                            {(user?.name || 'L').charAt(0).toUpperCase()}
                        </div>
                    </div>
                </header>

                <div className="flex min-h-[calc(100vh-57px)]">
                    <aside className="flex w-64 shrink-0 flex-col border-r border-line-soft bg-surface px-3 py-5">
                        <p className="mb-3 px-3 text-[10px] font-semibold tracking-wide text-ink-faint uppercase">
                            Lupon Desk Access
                        </p>

                        <nav aria-label="Lupon main menu" className="space-y-1">
                            <SidebarLink
                                href="/lupon/dashboard"
                                icon={LayoutDashboard}
                                label="Overview"
                                active={active === 'overview' || active === 'dashboard'}
                            />
                            <SidebarLink
                                href="/lupon/complaints"
                                icon={MessageSquare}
                                label="Complaint Submissions"
                                active={active === 'complaints' || active === 'payments'}
                            />

                            <div>
                                <div className="flex items-center gap-1">
                                    <SidebarLink
                                        href="/lupon/cases"
                                        icon={FolderOpen}
                                        label="Cases"
                                        active={casesActive}
                                        className="flex-1"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setCasesOpen((open) => !open)}
                                        className="flex h-10 w-9 shrink-0 items-center justify-center rounded-lg text-ink-muted transition hover:bg-canvas hover:text-ink"
                                        aria-label={casesOpen ? 'Collapse case navigation' : 'Expand case navigation'}
                                        aria-expanded={casesOpen}
                                        aria-controls="lupon-cases-subnav"
                                    >
                                        <ChevronDown className={`size-4 transition-transform ${casesOpen ? 'rotate-180' : ''}`} />
                                    </button>
                                </div>

                                {casesOpen && (
                                    <div id="lupon-cases-subnav" className="mt-1 space-y-1 lg:ml-3">
                                        {caseNavigation.map((item) => (
                                            <CaseSidebarLink
                                                key={item.id}
                                                href={item.href}
                                                label={item.label}
                                                active={casesActive && activeStage === item.id}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>

                            <SidebarLink
                                href="/lupon/tagapamayapa"
                                icon={Users}
                                label="Lupon Tagapamayapa"
                                active={active === 'tagapamayapa'}
                            />
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

function SidebarLink({
    href,
    icon: Icon,
    label,
    active = false,
    className = '',
}: {
    href: string;
    icon: LucideIcon;
    label: string;
    active?: boolean;
    className?: string;
}) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                active ? 'bg-brand-soft text-brand' : 'text-ink-muted hover:bg-canvas hover:text-ink'
            } ${className}`}
        >
            <Icon className="size-4 shrink-0" />
            <span>{label}</span>
        </Link>
    );
}

function CaseSidebarLink({ href, label, active }: { href: string; label: string; active: boolean }) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-2 rounded-lg py-2 pr-3 pl-9 text-sm font-medium transition ${
                active ? 'bg-brand-soft text-brand' : 'text-ink-muted hover:bg-canvas hover:text-ink'
            }`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-brand' : 'bg-line'}`} />
            <span>{label}</span>
        </Link>
    );
}
