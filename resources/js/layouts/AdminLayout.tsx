import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    BarChart3,
    Building2,
    ClipboardCheck,
    ClipboardList,
    FileBarChart,
    FileText,
    LayoutDashboard,
    Megaphone,
    Network,
    Phone,
    ScanLine,
    Settings,
    UserCog,
    Users,
} from 'lucide-react';

import DeskLayout, { DeskNavGroup, DeskNavLink } from '@/layouts/DeskLayout';

type AdminPage =
    | 'overview'
    | 'residents'
    | 'qr-scanner'
    | 'announcement-approvals'
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

export default function AdminLayout({ children, active }: AdminLayoutProps) {
    const { auth } = usePage<{
        auth?: {
            user?: { name?: string };
            activeBarangay?: { id: number; name: string } | null;
        };
    }>().props;

    const name = auth?.user?.name || 'Barangay Admin';

    return (
        <DeskLayout
            portalLabel="Admin Portal"
            user={{ name, role: 'Administrator' }}
            searchPlaceholder="Search residents or requests"
            nav={
                <>
                    <DeskNavGroup>
                        <DeskNavLink
                            href="/admin"
                            icon={LayoutDashboard}
                            label="Overview"
                            active={active === 'overview'}
                        />
                        <DeskNavLink
                            href="/admin/residents"
                            icon={Users}
                            label="Residents"
                            active={active === 'residents'}
                        />
                        <DeskNavLink
                            href="/admin/qr-scanner"
                            icon={ScanLine}
                            label="QR Scanner"
                            active={active === 'qr-scanner'}
                        />
                        <DeskNavLink
                            href="/admin/announcements/approvals"
                            icon={Megaphone}
                            label="Announcement Approvals"
                            active={active === 'announcement-approvals'}
                        />
                        <DeskNavLink
                            href="/admin/certificates"
                            icon={FileText}
                            label="Certificate Requests"
                            active={active === 'certificates'}
                        />
                    </DeskNavGroup>

                    <DeskNavGroup label="Barangay Records">
                        <DeskNavLink
                            href="/admin/sectors"
                            icon={Building2}
                            label="Sectors"
                            active={active === 'sectors'}
                        />
                        <DeskNavLink
                            href="/admin/sector-applications"
                            icon={ClipboardCheck}
                            label="Sector Applications"
                            active={active === 'sector-applications'}
                        />
                        <DeskNavLink
                            href="/admin/staff"
                            icon={UserCog}
                            label="Staff"
                            active={active === 'staff'}
                        />
                        <DeskNavLink
                            href="/admin/population"
                            icon={BarChart3}
                            label="Population"
                            active={active === 'population'}
                        />
                        <DeskNavLink
                            href="/admin/reports"
                            icon={FileBarChart}
                            label="Reports"
                            active={active === 'reports'}
                        />
                        <DeskNavLink
                            href="/admin/org-chart"
                            icon={Network}
                            label="Org Chart"
                            active={active === 'org-chart'}
                        />
                        <DeskNavLink
                            href="/admin/emergency-numbers"
                            icon={Phone}
                            label="Emergency Numbers"
                            active={active === 'emergency-numbers'}
                        />
                    </DeskNavGroup>

                    <DeskNavGroup label="System">
                        <DeskNavLink
                            href="/admin/audit-log"
                            icon={ClipboardList}
                            label="Audit Log"
                            active={active === 'audit-log'}
                        />
                        <DeskNavLink
                            href="/admin/settings"
                            icon={Settings}
                            label="Settings"
                            active={active === 'settings'}
                        />
                    </DeskNavGroup>
                </>
            }
        >
            {children}
        </DeskLayout>
    );
}
