import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    Banknote,
    BookOpen,
    CalendarDays,
    FileText,
    LayoutDashboard,
    Megaphone,
    Network,
} from 'lucide-react';

import DeskLayout, { DeskNavGroup, DeskNavLink } from '@/layouts/DeskLayout';

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

export default function ClerkLayout({
    children,
    active,
    user,
}: ClerkLayoutProps) {
    const { auth } = usePage<{
        auth?: {
            user?: { name?: string };
            activeBarangay?: { id: number; name: string } | null;
        };
    }>().props;

    const name = user?.name || auth?.user?.name || 'Clerk Staff';

    return (
        <DeskLayout
            portalLabel="Clerk Portal"
            user={{ name, role: 'Barangay Clerk' }}
            searchPlaceholder="Search certificates or records"
            nav={
                <>
                    <DeskNavGroup>
                        <DeskNavLink
                            href="/clerk/dashboard"
                            icon={LayoutDashboard}
                            label="Overview"
                            active={active === 'overview'}
                        />
                        <DeskNavLink
                            href="/clerk/certificates"
                            icon={FileText}
                            label="Certificates"
                            active={active === 'certificates'}
                        />
                    </DeskNavGroup>

                    <DeskNavGroup label="Community">
                        <DeskNavLink
                            href="/clerk/updates"
                            icon={Megaphone}
                            label="Updates"
                            active={active === 'updates'}
                        />
                        <DeskNavLink
                            href="/clerk/programs"
                            icon={CalendarDays}
                            label="Programs"
                            active={active === 'programs'}
                        />
                    </DeskNavGroup>

                    <DeskNavGroup label="Barangay Records">
                        <DeskNavLink
                            href="/clerk/disbursements"
                            icon={Banknote}
                            label="Disbursements"
                            active={active === 'disbursements'}
                        />
                        <DeskNavLink
                            href="/clerk/citizen-charter"
                            icon={BookOpen}
                            label="Citizen's Charter"
                            active={active === 'citizen-charter'}
                        />
                        <DeskNavLink
                            href="/clerk/org-chart"
                            icon={Network}
                            label="Org Chart"
                            active={active === 'org-chart'}
                        />
                    </DeskNavGroup>
                </>
            }
        >
            {children}
        </DeskLayout>
    );
}
