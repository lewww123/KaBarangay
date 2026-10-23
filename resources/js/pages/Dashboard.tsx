import { Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type StatCardProps = {
    title: string;
    value: string | number;
    subtitle: string;
    href?: string;
    unavailable?: boolean;
    dropdownContent?: ReactNode;
};

type SectorRowProps = {
    icon: string;
    title: string;
    description: string;
    href?: string;
    last?: boolean;
    unavailable?: boolean;
};

type DashboardProps = {
    stats?: {
        residents: number;
        pendingAnnouncements: number;
        activeStaff: number;
        puroks: number;
        releasedCertificates: number;
    };
    puroks?: { id: number; name: string }[];
    notifications?: {
        id: string;
        icon: string;
        title: string;
        description: string;
        time: string;
        href: string;
    }[];
    activity?: {
        pendingApprovals: number;
        activeStaff: number;
    };
};

export default function Dashboard({
    stats = {
        residents: 0,
        pendingAnnouncements: 0,
        activeStaff: 0,
        puroks: 0,
        releasedCertificates: 0,
    },
    puroks = [],
    notifications = [],
    activity = { pendingApprovals: 0, activeStaff: 0 },
}: DashboardProps) {
    const [openMenu, setOpenMenu] = useState<string | null>(null);

    return (
        <>
            <Head title="Admin Dashboard" />

            <AdminLayout active="overview">

                {/* PAGE HEADER */}
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Admin Dashboard
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        System overview and barangay activity
                    </p>
                </div>

                {/* STATISTICS */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">

                    <StatCard
                        title="Residents"
                        value={stats.residents}
                        subtitle="Registered"
                        href="/admin/residents"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    {/* ANNOUNCEMENT APPROVALS STAT CARD */}
                    <StatCard
                        title="Announcements"
                        value={stats.pendingAnnouncements}
                        subtitle="Pending approval"
                        href="/admin/announcements/approvals"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    <StatCard
                        title="Staff"
                        value={stats.activeStaff}
                        subtitle="Active accounts"
                        href="/admin/staff"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    <StatCard
                        title="Puroks"
                        value={stats.puroks}
                        subtitle="Total puroks"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                        dropdownContent={puroks.length > 0 ? (
                            <div className="max-h-48 overflow-auto">
                                {puroks.map((p) => (
                                    <div key={p.id} className="px-3 py-2 text-xs text-gray-700 border-b border-gray-50 last:border-b-0">
                                        {p.name}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="px-3 py-2 text-xs text-gray-400">No puroks found</div>
                        )}
                    />

                    {/* 
                     * Issued Certificates (deferred destination)
                     * Destination will be linked to the Sector page containing the Certificates section
                     * in a future approved batch. Do not link to /admin/reports or /admin/certificates.
                     */}
                    <StatCard
                        title="Issued Certificates"
                        value={stats.releasedCertificates}
                        subtitle="Completed (Released)"
                        unavailable={true}
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    {/* Cases (no Lupon case data source available) */}
                    <StatCard
                        title="Cases"
                        value="—"
                        subtitle="Open cases (unavailable)"
                        unavailable={true}
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                </div>

                {/* SYSTEM OVERVIEW + NOTIFICATIONS */}
                <div className="mt-7 grid grid-cols-1 gap-6 xl:grid-cols-2">

                    {/* SYSTEM OVERVIEW */}
                    <section>

                        <div className="mb-4 flex items-center justify-between">

                            <div>
                                <h2 className="text-base font-semibold text-gray-800">
                                    System Overview
                                </h2>

                                <p className="mt-1 text-xs text-gray-500">
                                    Barangay service modules
                                </p>
                            </div>

                            <Link
                                href="/admin/reports"
                                className="text-xs font-medium text-[#3f6f3d] hover:underline"
                            >
                                Reports
                            </Link>

                        </div>

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">

                            {/* ANNOUNCEMENT MODULE ROW */}
                            <SectorRow
                                icon="📢"
                                title="Announcement Approvals"
                                description="Pending & reviewed announcements"
                                href="/admin/announcements/approvals"
                            />

                            {/* Barangay Certificates destination deferred to Sector page with Certificates section */}
                            <SectorRow
                                icon="▣"
                                title="Barangay Certificates"
                                description="Certificates (destination deferred)"
                                unavailable={true}
                            />

                            <SectorRow
                                icon="⚖"
                                title="Lupong Tagapamayapa"
                                description="Cases & hearings (unavailable)"
                                unavailable={true}
                            />

                            <SectorRow
                                icon="♿"
                                title="PWD"
                                description="Registered PWD members (no member management page)"
                                unavailable={true}
                            />

                            <SectorRow
                                icon="♙"
                                title="Senior Citizen"
                                description="Registered senior citizens (no member management page)"
                                unavailable={true}
                                last
                            />

                        </div>

                    </section>

                    {/* NOTIFICATIONS */}
                    <section>

                        <div className="mb-4 flex items-center justify-between">

                            <div>
                                <h2 className="text-base font-semibold text-gray-800">
                                    Notifications
                                </h2>

                                <p className="mt-1 text-xs text-gray-500">
                                    Recent system activity
                                </p>
                            </div>

                            <Link
                                href="/admin/audit-log"
                                className="text-xs font-medium text-[#3f6f3d] hover:underline"
                            >
                                View all
                            </Link>

                        </div>

                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">

                            {notifications.length === 0 && (
                                <div className="px-5 py-10 text-center text-sm text-gray-400">
                                    No recent notifications.
                                </div>
                            )}

                            {notifications.map((notification, idx) => (
                                <Notification
                                    key={notification.id}
                                    icon={notification.icon}
                                    title={notification.title}
                                    description={notification.description}
                                    time={notification.time}
                                    href={notification.href}
                                    last={idx === notifications.length - 1}
                                />
                            ))}

                        </div>

                    </section>

                </div>

                {/* ACTIVITY SUMMARY */}
                <section className="mt-7">

                    <div className="mb-4 flex items-center justify-between">

                        <div>
                            <h2 className="text-base font-semibold text-gray-800">
                                Activity Summary
                            </h2>

                            <p className="mt-1 text-xs text-gray-500">
                                Current barangay records
                            </p>
                        </div>

                        <Link
                            href="/admin/reports"
                            className="text-xs font-medium text-[#3f6f3d] hover:underline"
                        >
                            View Reports
                        </Link>

                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">

                        <ActivityCard
                            title="Pending Approvals"
                            value={activity.pendingApprovals.toString()}
                            subtitle="Announcements awaiting review"
                            href="/admin/announcements/approvals"
                        />

                        <ActivityCard
                            title="Open Cases"
                            value="—"
                            subtitle="Lupong Tagapamayapa (unavailable)"
                            unavailable={true}
                        />

                        <ActivityCard
                            title="Active Staff"
                            value={activity.activeStaff.toString()}
                            subtitle="Currently active"
                            href="/admin/staff"
                        />

                    </div>

                </section>

            </AdminLayout>
        </>
    );
}

/* -------------------------------------------------------------------------- */
/* Stat Card                                                                  */
/* -------------------------------------------------------------------------- */

function StatCard({
    title,
    value,
    subtitle,
    href,
    unavailable = false,
    dropdownContent,
    openMenu,
    setOpenMenu,
}: StatCardProps & {
    openMenu: string | null;
    setOpenMenu: (value: string | null) => void;
}) {
    const menuKey = title.toLowerCase();
    const isPuroks = title === 'Puroks';
    const canNavigate = !unavailable && !!href;
    const hasDropdown = !!dropdownContent;
    const isOpen = openMenu === menuKey;
    const toggleMenu = () =>
        setOpenMenu(isOpen ? null : menuKey);

    return (
        <div className="relative rounded-lg bg-[#3f6f3d] p-5 text-white shadow-sm">

            <div className="flex items-start justify-between">

                <p className="text-xs font-medium uppercase tracking-wide text-white/80">
                    {title}
                </p>

                <div className="relative">

                    <button
                        type="button"
                        onClick={toggleMenu}
                        aria-expanded={isOpen}
                        aria-haspopup="true"
                        aria-label={`${title} actions`}
                        className="flex h-6 w-6 items-center justify-center rounded-full bg-white/10 text-xs hover:bg-white/20"
                    >
                        ⋮
                    </button>

                    {isOpen && (
                        <div className="absolute right-0 z-20 mt-2 min-w-[10rem] rounded-md border border-gray-200 bg-white py-1 text-gray-700 shadow-lg">
                            {isPuroks && dropdownContent ? (
                                <div>{dropdownContent}</div>
                            ) : canNavigate && href ? (
                                <Link
                                    href={href}
                                    onClick={() => setOpenMenu(null)}
                                    className="block px-3 py-2 text-xs hover:bg-gray-100"
                                >
                                    View Details
                                </Link>
                            ) : (
                                <div className="px-3 py-2 text-xs text-gray-400">
                                    Unavailable
                                </div>
                            )}
                        </div>
                    )}

                </div>

            </div>

            {canNavigate && href ? (
                <Link href={href} className="block">
                    <p className="mt-4 text-3xl font-semibold">
                        {value}
                    </p>

                    <p className="mt-1 text-xs text-white/70">
                        {subtitle}
                    </p>
                </Link>
            ) : hasDropdown ? (
                <button
                    type="button"
                    onClick={toggleMenu}
                    aria-expanded={isOpen}
                    aria-haspopup="true"
                    className="block w-full cursor-pointer text-left"
                >
                    <span className="mt-4 block text-3xl font-semibold">
                        {value}
                    </span>

                    <span className="mt-1 block text-xs text-white/70">
                        {subtitle}
                    </span>
                </button>
            ) : (
                <div className="block">
                    <p className="mt-4 text-3xl font-semibold">
                        {value}
                    </p>

                    <p className="mt-1 text-xs text-white/70">
                        {subtitle}
                    </p>
                </div>
            )}

        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Notification                                                               */
/* -------------------------------------------------------------------------- */

function Notification({
    icon,
    title,
    description,
    time,
    href,
    last = false,
}: {
    icon: string;
    title: string;
    description: string;
    time: string;
    href: string;
    last?: boolean;
}) {
    return (
        <Link
            href={href}
            className={`flex items-center justify-between gap-4 px-4 py-4 transition hover:bg-gray-50 ${
                !last ? 'border-b border-gray-100' : ''
            }`}
        >

            <div className="flex min-w-0 items-center gap-3">

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#edf4ea] text-sm font-semibold text-[#3f6f3d]">
                    {icon}
                </div>

                <div className="min-w-0">

                    <p className="truncate text-sm font-medium text-gray-800">
                        {title}
                    </p>

                    <p className="mt-0.5 text-xs text-gray-500">
                        {description}
                    </p>

                </div>

            </div>

            <span className="shrink-0 text-[10px] text-gray-400">
                {time}
            </span>

        </Link>
    );
}

/* -------------------------------------------------------------------------- */
/* System Overview Row                                                        */
/* -------------------------------------------------------------------------- */

function SectorRow({
    icon,
    title,
    description,
    href,
    last = false,
    unavailable = false,
}: SectorRowProps) {
    const canNavigate = !unavailable && !!href;

    if (!canNavigate) {
        return (
            <div
                className={`flex items-center justify-between gap-4 px-5 py-4 ${
                    !last ? 'border-b border-gray-100' : ''
                }`}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[#edf4ea] text-sm text-[#3f6f3d]">
                        {icon}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-gray-800">
                            {title}
                        </p>
                        <p className="truncate text-xs text-gray-500">
                            {description}
                        </p>
                    </div>
                </div>
                <span className="shrink-0 text-xs font-medium text-gray-400">
                    Unavailable
                </span>
            </div>
        );
    }

    return (
        <Link
            href={href as string}
            className={`flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50 ${
                !last ? 'border-b border-gray-100' : ''
            }`}
        >

            <div className="flex min-w-0 items-center gap-3">

                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-[#edf4ea] text-sm text-[#3f6f3d]">
                    {icon}
                </div>

                <div className="min-w-0">

                    <p className="truncate text-sm font-medium text-gray-800">
                        {title}
                    </p>

                    <p className="truncate text-xs text-gray-500">
                        {description}
                    </p>

                </div>

            </div>

            <span className="shrink-0 text-xs font-medium text-[#3f6f3d]">
                View
            </span>

        </Link>
    );
}

/* -------------------------------------------------------------------------- */
/* Activity Card                                                              */
/* -------------------------------------------------------------------------- */

function ActivityCard({
    title,
    value,
    subtitle,
    href,
    unavailable = false,
}: {
    title: string;
    value: string;
    subtitle: string;
    href?: string;
    unavailable?: boolean;
}) {
    const canNavigate = !unavailable && !!href;

    if (!canNavigate) {
        return (
            <div className="rounded-lg border border-gray-200 bg-white p-5">
                <p className="text-xs font-medium uppercase tracking-wide text-gray-400">
                    {title}
                </p>

                <p className="mt-3 text-3xl font-semibold text-gray-800">
                    {value}
                </p>

                <p className="mt-1 text-xs text-gray-500">
                    {subtitle}
                </p>
            </div>
        );
    }

    return (
        <Link
            href={href as string}
            className="rounded-lg border border-gray-200 bg-white p-5 transition hover:border-[#3f6f3d] hover:shadow-sm"
        >

            <p className="text-xs font-medium uppercase tracking-wide text-gray-400">
                {title}
            </p>

            <p className="mt-3 text-3xl font-semibold text-gray-800">
                {value}
            </p>

            <p className="mt-1 text-xs text-gray-500">
                {subtitle}
            </p>

        </Link>
    );
}