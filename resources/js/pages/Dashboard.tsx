import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type StatCardProps = {
    title: string;
    value: string;
    subtitle: string;
    href: string;
};

type SectorRowProps = {
    icon: string;
    title: string;
    description: string;
    href: string;
    last?: boolean;
};

export default function Dashboard() {
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
                        value="8"
                        subtitle="Registered"
                        href="/admin/residents"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    {/* ANNOUNCEMENT APPROVALS STAT CARD */}
                    <StatCard
                        title="Announcements"
                        value="2"
                        subtitle="Pending approval"
                        href="/admin/announcements/approvals"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    <StatCard
                        title="Staff"
                        value="6"
                        subtitle="Active accounts"
                        href="/admin/staff"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    <StatCard
                        title="Puroks"
                        value="12"
                        subtitle="Total puroks"
                        href="/admin/residents"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    <StatCard
                        title="Issued Certificates"
                        value="3"
                        subtitle="Completed"
                        href="/admin/reports"
                        openMenu={openMenu}
                        setOpenMenu={setOpenMenu}
                    />

                    <StatCard
                        title="Cases"
                        value="0"
                        subtitle="Open cases"
                        href="/admin/sectors"
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

                            <SectorRow
                                icon="▣"
                                title="Barangay Certificates"
                                description="Pending & issued certificates"
                                href="/admin/reports"
                            />

                            <SectorRow
                                icon="⚖"
                                title="Lupong Tagapamayapa"
                                description="Cases & hearings"
                                href="/admin/sectors"
                            />

                            <SectorRow
                                icon="♿"
                                title="PWD"
                                description="Registered PWD members"
                                href="/admin/sectors"
                            />

                            <SectorRow
                                icon="♙"
                                title="Senior Citizen"
                                description="Registered senior citizens"
                                href="/admin/sectors"
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

                            <Notification
                                icon="📢"
                                title="New announcement pending approval"
                                description="Announcement Approvals"
                                time="Today"
                                href="/admin/announcements/approvals"
                            />

                            <Notification
                                icon="!"
                                title="5 certificates pending processing"
                                description="Certificate Issuance"
                                time="Today"
                                href="/admin/reports"
                            />

                            <Notification
                                icon="♙"
                                title="New resident record added"
                                description="Resident Management"
                                time="Today"
                                href="/admin/residents"
                            />

                            <Notification
                                icon="♟"
                                title="Staff account activity detected"
                                description="Staff Management"
                                time="Yesterday"
                                href="/admin/staff"
                            />

                            <Notification
                                icon="◷"
                                title="System activity recorded"
                                description="Audit Log"
                                time="Yesterday"
                                href="/admin/audit-log"
                                last
                            />

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
                            value="2"
                            subtitle="Announcements awaiting review"
                            href="/admin/announcements/approvals"
                        />

                        <ActivityCard
                            title="Open Cases"
                            value="0"
                            subtitle="Lupong Tagapamayapa"
                            href="/admin/sectors"
                        />

                        <ActivityCard
                            title="Active Staff"
                            value="6"
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
    openMenu,
    setOpenMenu,
}: StatCardProps & {
    openMenu: string | null;
    setOpenMenu: (value: string | null) => void;
}) {
    const menuKey = title.toLowerCase();

    return (
        <div className="relative rounded-lg bg-[#3f6f3d] p-5 text-white shadow-sm">

            <div className="flex items-start justify-between">

                <p className="text-xs font-medium uppercase tracking-wide text-white/80">
                    {title}
                </p>

                <div className="relative">

                    <button
                        type="button"
                        onClick={() =>
                            setOpenMenu(
                                openMenu === menuKey ? null : menuKey,
                            )
                        }
                        className="flex h-6 w-6 items-center justify-center rounded-full bg-white/10 text-xs hover:bg-white/20"
                    >
                        ⋮
                    </button>

                    {openMenu === menuKey && (
                        <div className="absolute right-0 z-20 mt-2 w-32 rounded-md border border-gray-200 bg-white py-1 text-gray-700 shadow-lg">

                            <Link
                                href={href}
                                onClick={() => setOpenMenu(null)}
                                className="block px-3 py-2 text-xs hover:bg-gray-100"
                            >
                                View Details
                            </Link>

                        </div>
                    )}

                </div>

            </div>

            <Link href={href} className="block">

                <p className="mt-4 text-3xl font-semibold">
                    {value}
                </p>

                <p className="mt-1 text-xs text-white/70">
                    {subtitle}
                </p>

            </Link>

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
}: SectorRowProps) {
    return (
        <Link
            href={href}
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
}: {
    title: string;
    value: string;
    subtitle: string;
    href: string;
}) {
    return (
        <Link
            href={href}
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