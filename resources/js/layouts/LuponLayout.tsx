import { usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import {
    ChevronDown,
    FolderOpen,
    LayoutDashboard,
    MessageSquare,
    Users,
} from 'lucide-react';

import DeskLayout, {
    DeskNavGroup,
    DeskNavLink,
    DeskSubNavLink,
    useDeskSidebar,
} from '@/layouts/DeskLayout';

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
    const shared = usePage<{
        auth?: {
            user?: { name?: string };
            activeBarangay?: { id: number; name: string } | null;
        };
    }>().props;
    user = user ?? shared.auth?.user;

    const casesActive = active === 'cases';
    const [casesOpen, setCasesOpen] = useState(casesActive);
    const activeStage = caseStage ?? 'all';

    useEffect(() => {
        if (casesActive) {
            setCasesOpen(true);
        }
    }, [casesActive]);

    const name =
        user?.name || shared.auth?.user?.name || 'Lupon Staff';

    return (
        <DeskLayout
            portalLabel="Lupon Portal"
            user={{ name, role: 'Lupon Secretary' }}
            searchPlaceholder="Search complaints or cases"
            nav={
                <LuponNav
                    active={active}
                    casesActive={casesActive}
                    casesOpen={casesOpen}
                    onToggleCases={() => setCasesOpen((open) => !open)}
                    activeStage={activeStage}
                />
            }
        >
            {children}
        </DeskLayout>
    );
}

function LuponNav({
    active,
    casesActive,
    casesOpen,
    onToggleCases,
    activeStage,
}: {
    active: LuponPage;
    casesActive: boolean;
    casesOpen: boolean;
    onToggleCases: () => void;
    activeStage: LuponCaseStage;
}) {
    const { collapsed } = useDeskSidebar();

    return (
        <DeskNavGroup>
            <DeskNavLink
                href="/lupon/dashboard"
                icon={LayoutDashboard}
                label="Overview"
                active={active === 'overview' || active === 'dashboard'}
            />
            <DeskNavLink
                href="/lupon/complaints"
                icon={MessageSquare}
                label="Complaint Submissions"
                active={active === 'complaints' || active === 'payments'}
            />

            <div>
                <div className="flex items-center gap-1">
                    <DeskNavLink
                        href="/lupon/cases"
                        icon={FolderOpen}
                        label="Cases"
                        active={casesActive}
                        className="flex-1"
                    />
                    {!collapsed && (
                        <button
                            type="button"
                            onClick={onToggleCases}
                            className="flex h-10 w-9 shrink-0 items-center justify-center rounded-lg text-white/65 transition-colors hover:bg-white/10 hover:text-white"
                            aria-label={
                                casesOpen
                                    ? 'Collapse case navigation'
                                    : 'Expand case navigation'
                            }
                            aria-expanded={casesOpen}
                            aria-controls="lupon-cases-subnav"
                        >
                            <ChevronDown
                                className={`size-4 transition-transform ${casesOpen ? 'rotate-180' : ''}`}
                            />
                        </button>
                    )}
                </div>

                {casesOpen && !collapsed && (
                    <div
                        id="lupon-cases-subnav"
                        className="mt-1 space-y-1 lg:ml-3"
                    >
                        {caseNavigation.map((item) => (
                            <DeskSubNavLink
                                key={item.id}
                                href={item.href}
                                label={item.label}
                                active={casesActive && activeStage === item.id}
                            />
                        ))}
                    </div>
                )}
            </div>

            <DeskNavLink
                href="/lupon/tagapamayapa"
                icon={Users}
                label="Lupon Tagapamayapa"
                active={active === 'tagapamayapa'}
            />
        </DeskNavGroup>
    );
}
