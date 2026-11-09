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

type PwdPage =
    | 'dashboard'
    | 'announcements'
    | 'updates'
    | 'programs'
    | 'residents'
    | 'applications';

type PwdLayoutProps = {
    children: ReactNode;
    active: PwdPage;
    user?: {
        name?: string;
    };
};

export default function PwdLayout({
    children,
    active,
    user,
}: PwdLayoutProps) {
    const { auth } = usePage<{
        auth?: {
            user?: { name?: string };
            activeBarangay?: { id: number; name: string } | null;
        };
    }>().props;

    const name = user?.name || auth?.user?.name || 'PWD Staff';

    return (
        <DeskLayout
            portalLabel="PWD Portal"
            user={{ name, role: 'PWD Affairs Officer' }}
            searchPlaceholder="Search PWD members or applications"
            nav={
                <>
                    <DeskNavGroup>
                        <DeskNavLink
                            href="/pwd/dashboard"
                            icon={LayoutDashboard}
                            label="Dashboard"
                            active={active === 'dashboard'}
                        />
                        <DeskNavLink
                            href="/pwd/residents"
                            icon={Users}
                            label="Members"
                            active={active === 'residents'}
                        />
                        <DeskNavLink
                            href="/pwd/applications"
                            icon={ClipboardCheck}
                            label="Applications"
                            active={active === 'applications'}
                        />
                    </DeskNavGroup>

                    <DeskNavGroup label="Community">
                        <DeskNavLink
                            href="/pwd/updates"
                            icon={Megaphone}
                            label="Updates"
                            active={active === 'updates'}
                        />
                        <DeskNavLink
                            href="/pwd/programs"
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
