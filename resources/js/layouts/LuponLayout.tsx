import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { formatBarangayLabel } from '@/lib/formatBarangayLabel';

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
    {
        id: 'mediation',
        href: '/lupon/cases?stage=mediation',
        label: 'Mediation',
    },
    {
        id: 'conciliation',
        href: '/lupon/cases?stage=conciliation',
        label: 'Conciliation',
    },
    {
        id: 'arbitration',
        href: '/lupon/cases?stage=arbitration',
        label: 'Arbitration',
    },
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

    const shared = usePage<{
        auth?: {
            user?: { name?: string };
            activeBarangay?: { id: number; name: string } | null;
        };
    }>().props;
    user = user ?? shared.auth?.user;
    const brand = formatBarangayLabel(
        barangayName ?? shared.auth?.activeBarangay?.name ?? null,
    );
    const casesActive = active === 'cases';
    const [casesOpen, setCasesOpen] = useState(casesActive);
    const activeStage = caseStage ?? 'all';

    useEffect(() => {
        if (casesActive) {
            setCasesOpen(true);
        }
    }, [casesActive]);

    return (
        <div className="min-h-screen bg-[#f7f6f0] text-gray-800">
            {/* TOP HEADER */}
            <header className="flex min-h-14 items-center justify-between bg-[#3f6f3d] px-4 py-3 text-white sm:px-5">
                <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-brand">
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
                        {user?.name || 'Lupon Staff'}
                        {user?.staff_id ? ` (${user.staff_id})` : ''}
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

                    <nav
                        aria-label="Lupon main menu"
                        className="grid grid-cols-2 gap-1 lg:block lg:space-y-1"
                    >
                        <SidebarLink
                            href="/lupon/dashboard"
                            icon="⚖️"
                            label="Overview"
                            active={
                                active === 'overview' || active === 'dashboard'
                            }
                        />
                        <SidebarLink
                            href="/lupon/complaints"
                            icon="📋"
                            label="Complaint Submissions"
                            active={
                                active === 'complaints' || active === 'payments'
                            }
                        />

                        <div className="col-span-2 lg:col-span-1">
                            <div className="flex items-center gap-1">
                                <SidebarLink
                                    href="/lupon/cases"
                                    icon="📂"
                                    label="Cases"
                                    active={casesActive}
                                    className="flex-1"
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        setCasesOpen((open) => !open)
                                    }
                                    className="flex h-[42px] w-9 shrink-0 items-center justify-center rounded-md text-gray-500 transition hover:bg-gray-100 hover:text-gray-800"
                                    aria-label={
                                        casesOpen
                                            ? 'Collapse case navigation'
                                            : 'Expand case navigation'
                                    }
                                    aria-expanded={casesOpen}
                                    aria-controls="lupon-cases-subnav"
                                >
                                    <span
                                        className={`text-xs transition-transform ${casesOpen ? 'rotate-90' : ''}`}
                                    >
                                        ▸
                                    </span>
                                </button>
                            </div>

                            {casesOpen && (
                                <div
                                    id="lupon-cases-subnav"
                                    className="mt-1 space-y-1 lg:ml-3"
                                >
                                    {caseNavigation.map((item) => (
                                        <CaseSidebarLink
                                            key={item.id}
                                            href={item.href}
                                            label={item.label}
                                            active={
                                                casesActive &&
                                                activeStage === item.id
                                            }
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        <SidebarLink
                            href="/lupon/tagapamayapa"
                            icon="👥"
                            label="Lupon Tagapamayapa"
                            active={active === 'tagapamayapa'}
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
    className = '',
}: {
    href: string;
    icon: string;
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
            className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition ${
                active
                    ? 'bg-[#e8f1e4] text-brand'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800'
            } ${className}`}
        >
            <span className="w-4 text-center">{icon}</span>
            <span>{label}</span>
        </Link>
    );
}

function CaseSidebarLink({
    href,
    label,
    active,
}: {
    href: string;
    label: string;
    active: boolean;
}) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={href}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-2 rounded-md py-2 pr-3 pl-9 text-sm font-medium transition ${
                active
                    ? 'bg-[#e8f1e4] text-brand'
                    : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800'
            }`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${active ? 'bg-[#3f6f3d]' : 'bg-gray-300'}`}
            />
            <span>{label}</span>
        </Link>
    );
}
