import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { LogoMark } from '@/components/Logo';
import {
    BarChart3,
    CalendarDays,
    ChevronRight,
    FileText,
    FolderOpen,
    Gavel,
    LayoutDashboard,
    LogOut,
    MessageSquare,
    Users,
    User,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';

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
    | 'walk-in'
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

type NavItem = {
    label: string;
    to: string;
    icon: LucideIcon;
    active: boolean;
};

export default function LuponLayout({
    children,
    active,
    caseStage = 'all',
    user,
    barangayName,
}: LuponLayoutProps) {
    const { auth } = usePage<{ auth?: { user?: LuponLayoutProps['user'] } }>()
        .props;
    const activeStage = caseStage;
    const staff = user ?? auth?.user;
    const casesActive = active === 'cases';
    const [casesOpen, setCasesOpen] = useState(casesActive);

    useEffect(() => {
        if (casesActive) {
            setCasesOpen(true);
        }
    }, [casesActive]);

    const overview: NavItem = {
        label: 'Overview',
        to: '/lupon/dashboard',
        icon: LayoutDashboard,
        active: active === 'dashboard' || active === 'overview',
    };
    const complaints: NavItem = {
        label: 'Complaint Submissions',
        to: '/lupon/complaints',
        icon: MessageSquare,
        active: active === 'complaints' || active === 'payments',
    };
    const cases: NavItem = {
        label: 'Cases',
        to: '/lupon/cases',
        icon: Gavel,
        active: casesActive,
    };
    const otherItems: NavItem[] = [
        {
            label: 'Hearings',
            to: '/lupon/hearings',
            icon: CalendarDays,
            active: active === 'hearings',
        },
        {
            label: 'Walk-in Intake',
            to: '/lupon/complaints/walk-in',
            icon: UserPlus,
            active: active === 'walk-in',
        },
        {
            label: 'Documents',
            to: '/lupon/documents',
            icon: FileText,
            active: active === 'documents',
        },
        {
            label: 'Registry',
            to: '/lupon/records',
            icon: FolderOpen,
            active: active === 'records',
        },
        {
            label: 'Lupon Tagapamayapa',
            to: '/lupon/tagapamayapa',
            icon: Users,
            active: active === 'tagapamayapa',
        },
        {
            label: 'Reports',
            to: '/lupon/reports',
            icon: BarChart3,
            active: active === 'reports',
        },
        {
            label: 'Profile',
            to: '/lupon/profile',
            icon: User,
            active: active === 'profile',
        },
    ];

    return (
        <div className="bg-canvas text-ink min-h-screen">
            <header className="border-line-soft bg-surface flex min-h-14 items-center justify-between gap-3 border-b px-5 py-3">
                <div className="flex items-center gap-3">
                    <LogoMark tone="onBrand" size="sm" />
                    <div className="leading-tight">
                        <p className="text-ink-strong text-sm font-semibold">
                            {barangayName || 'Barangay'}
                        </p>
                        <p className="text-ink-muted text-xs">Lupon Portal</p>
                    </div>
                </div>

                <div className="flex items-center gap-3">
                    <span className="text-ink-muted hidden text-sm sm:inline">
                        {staff?.name || 'Lupon Staff'}
                        {staff?.staff_id && ` (${staff.staff_id})`}
                    </span>
                    <div className="bg-brand-soft text-brand flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold">
                        {(staff?.name || 'L').charAt(0).toUpperCase()}
                    </div>
                </div>
            </header>

            <div className="flex min-h-[calc(100vh-57px)] flex-col lg:flex-row">
                <aside className="border-line-soft bg-surface flex shrink-0 flex-col border-b px-3 py-5 lg:w-60 lg:border-r lg:border-b-0">
                    <p className="text-ink-faint mb-3 px-3 text-[10px] font-semibold tracking-wide uppercase">
                        Lupon Desk Access
                    </p>
                    <nav
                        aria-label="Lupon main menu"
                        className="grid grid-cols-2 gap-1 lg:block lg:space-y-1"
                    >
                        <SidebarLink {...overview} />
                        <SidebarLink {...complaints} />
                        <div className="col-span-2">
                            <div className="flex items-center gap-1">
                                <SidebarLink {...cases} className="flex-1" />
                                <button
                                    type="button"
                                    onClick={() =>
                                        setCasesOpen((open) => !open)
                                    }
                                    className="text-ink-muted hover:bg-canvas hover:text-ink grid size-9 shrink-0 place-items-center rounded-lg transition"
                                    aria-label={
                                        casesOpen
                                            ? 'Collapse case stages'
                                            : 'Expand case stages'
                                    }
                                    aria-expanded={casesOpen}
                                    aria-controls="lupon-cases-subnav"
                                >
                                    <ChevronRight
                                        className={
                                            casesOpen
                                                ? 'size-4 rotate-90'
                                                : 'size-4'
                                        }
                                    />
                                </button>
                            </div>
                            <div
                                id="lupon-cases-subnav"
                                hidden={!casesOpen}
                                className="mt-1 space-y-1 pl-4"
                            >
                                {caseNavigation.map((item) => (
                                    <SidebarLink
                                        key={item.id}
                                        to={item.href}
                                        icon={FolderOpen}
                                        label={item.label}
                                        active={
                                            casesActive && activeStage === item.id
                                        }
                                    />
                                ))}
                            </div>
                        </div>
                        {otherItems.map((item) => (
                            <SidebarLink key={item.to} {...item} />
                        ))}
                    </nav>

                    <div className="border-line-soft mt-4 border-t pt-4 lg:mt-auto">
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="hover:bg-danger-soft flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-red-600 transition"
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
    );
}

function SidebarLink({
    label,
    to,
    icon: Icon,
    active,
    className = '',
}: NavItem & { className?: string }) {
    return (
        <Link
            prefetch="hover"
            cacheFor="10s"
            href={to}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                active
                    ? 'bg-brand-soft text-brand'
                    : 'text-ink-muted hover:bg-canvas hover:text-ink'
            } ${className}`}
        >
            <Icon className="size-4 shrink-0" />
            <span>{label}</span>
        </Link>
    );
}
