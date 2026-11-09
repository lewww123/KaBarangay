import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    CalendarDays,
    ClipboardCheck,
    LayoutDashboard,
    Megaphone,
    Users,
} from 'lucide-react';

import DeskLayout, { DeskNavGroup, DeskNavLink } from '@/layouts/DeskLayout';

type SeniorPage =
    | 'dashboard'
    | 'announcements'
    | 'updates'
    | 'programs'
    | 'residents'
    | 'applications';

type SeniorLayoutProps = {
    children: ReactNode;
    active: SeniorPage;
    user?: {
        name?: string;
    };
};

export default function SeniorLayout({
    children,
    active,
    user,
}: SeniorLayoutProps) {
    const { auth } = usePage<{
        auth?: {
            user?: { name?: string };
            activeBarangay?: { id: number; name: string } | null;
        };
    }>().props;

    const name = user?.name || auth?.user?.name || 'Senior Staff';

    return (
        <DeskLayout
            portalLabel="Senior Portal"
            user={{ name, role: 'OSCA Head' }}
            searchPlaceholder="Search senior members or applications"
            nav={
                <>
                    <DeskNavGroup>
                        <DeskNavLink
                            href="/senior/dashboard"
                            icon={LayoutDashboard}
                            label="Dashboard"
                            active={active === 'dashboard'}
                        />
                        <DeskNavLink
                            href="/senior/residents"
                            icon={Users}
                            label="Members"
                            active={active === 'residents'}
                        />
                        <DeskNavLink
                            href="/senior/applications"
                            icon={ClipboardCheck}
                            label="Applications"
                            active={active === 'applications'}
                        />
                    </DeskNavGroup>

                    <DeskNavGroup label="Community">
                        <DeskNavLink
                            href="/senior/updates"
                            icon={Megaphone}
                            label="Updates"
                            active={active === 'updates'}
                        />
                        <DeskNavLink
                            href="/senior/programs"
                            icon={CalendarDays}
                            label="Programs"
                            active={active === 'programs'}
                        />
                    </DeskNavGroup>
                </>
            }
        >
            {children}
        </DeskLayout>
    );
}
