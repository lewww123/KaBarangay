import { Deferred, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';

type DashboardProps = {
    stats?: {
        residents: number;
        totalPrograms: number;
        totalAnnouncements: number;
        puroks: number;
        cases?: number;
    };
    announcements?: {
        id: number;
        title: string;
        content: string;
        category: string;
        eventStartAt: string | null;
        location: string | null;
    }[];
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
        activeStaff: number;
    };
};

export default function Dashboard({
    stats = {
        residents: 0,
        totalPrograms: 0,
        totalAnnouncements: 0,
        puroks: 0,
        cases: 0,
    },
    puroks = [],
    announcements = [],
    notifications = [],
    activity = { activeStaff: 0 },
}: DashboardProps) {
    const [puroksOpen, setPuroksOpen] = useState(false);

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
                        Barangay records and activity
                    </p>
                </div>

                <Deferred
                    data={[
                        'stats',
                        'puroks',
                        'announcements',
                        'notifications',
                        'activity',
                    ]}
                    fallback={
                        <AdminPageSkeleton metrics={3} rows={4} columns={3} />
                    }
                >
                    {/* STATISTICS */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <SummaryCard
                            title="Residents"
                            value={stats.residents}
                            subtitle="Registered Residents"
                            href="/admin/residents"
                        />
                        <PuroksSummaryCard
                            value={stats.puroks}
                            puroks={puroks}
                            open={puroksOpen}
                            onToggle={() => setPuroksOpen(!puroksOpen)}
                        />
                        <SummaryCard
                            title="Cases"
                            value={stats.cases ?? 0}
                            subtitle="Lupon cases"
                            href="/admin/sectors?tab=lupon"
                        />
                    </div>

                    {/* BARANGAY ANNOUNCEMENTS + NOTIFICATIONS */}
                    <div className="mt-7 grid grid-cols-1 gap-6 xl:grid-cols-2">
                        {/* BARANGAY ANNOUNCEMENTS */}
                        <section>
                            <div className="mb-4 flex items-center justify-between">
                                <div>
                                    <h2 className="text-base font-semibold text-gray-800">
                                        Barangay Announcements
                                    </h2>

                                    <p className="mt-1 text-xs text-gray-500">
                                        Published announcements and programs
                                    </p>
                                </div>

                                <p className="text-xs font-medium text-[#3f6f3d]">
                                    Total Programs: {stats.totalPrograms} ·
                                    Total Announcements:{' '}
                                    {stats.totalAnnouncements}
                                </p>
                            </div>

                            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                                {announcements.length === 0 ? (
                                    <div className="px-5 py-10 text-center text-sm text-gray-400">
                                        No published announcements.
                                    </div>
                                ) : (
                                    announcements.map((announcement, index) => (
                                        <AnnouncementRow
                                            key={announcement.id}
                                            announcement={announcement}
                                            last={
                                                index ===
                                                announcements.length - 1
                                            }
                                        />
                                    ))
                                )}
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
                                title="Active Staff"
                                value={activity.activeStaff.toString()}
                                subtitle="Currently active"
                                href="/admin/staff"
                            />
                        </div>
                    </section>
                </Deferred>
            </AdminLayout>
        </>
    );
}

/* -------------------------------------------------------------------------- */
/* Summary Card                                                              */
/* -------------------------------------------------------------------------- */
function SummaryCard({
    title,
    value,
    subtitle,
    href,
}: {
    title: string;
    value: string | number;
    subtitle: string;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="rounded-lg bg-[#3f6f3d] p-5 text-white shadow-sm transition hover:bg-[#355f33]"
        >
            <p className="text-xs font-medium tracking-wide text-white/80 uppercase">
                {title}
            </p>
            <p className="mt-4 text-3xl font-semibold">{value}</p>
            <p className="mt-1 text-xs text-white/70">{subtitle}</p>
        </Link>
    );
}

function PuroksSummaryCard({
    value,
    puroks,
    open,
    onToggle,
}: {
    value: number;
    puroks: { id: number; name: string }[];
    open: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="relative rounded-lg bg-[#3f6f3d] p-5 text-white shadow-sm">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="block w-full text-left"
            >
                <p className="text-xs font-medium tracking-wide text-white/80 uppercase">
                    Puroks
                </p>
                <p className="mt-4 text-3xl font-semibold">{value}</p>
                <p className="mt-1 text-xs text-white/70">
                    Total Puroks · View list
                </p>
            </button>
            {open && (
                <div className="absolute inset-x-3 top-full z-20 mt-2 max-h-48 overflow-auto rounded-md border border-gray-200 bg-white py-1 text-xs text-gray-700 shadow-lg">
                    {puroks.length ? (
                        puroks.map((purok) => (
                            <div
                                key={purok.id}
                                className="border-b border-gray-50 px-3 py-2 last:border-0"
                            >
                                {purok.name}
                            </div>
                        ))
                    ) : (
                        <div className="px-3 py-2 text-gray-400">
                            No puroks found
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function AnnouncementRow({
    announcement,
    last,
}: {
    announcement: NonNullable<DashboardProps['announcements']>[number];
    last: boolean;
}) {
    const [expanded, setExpanded] = useState(false);
    return (
        <article
            className={`px-5 py-4 ${!last ? 'border-b border-gray-100' : ''}`}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-800">
                        {announcement.title}
                    </p>
                    <p className="mt-1 text-xs text-gray-500">
                        {announcement.category}
                        {announcement.eventStartAt
                            ? ` · ${announcement.eventStartAt}`
                            : ''}
                        {announcement.location
                            ? ` · ${announcement.location}`
                            : ''}
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="shrink-0 text-xs font-medium text-[#3f6f3d] hover:underline"
                    aria-expanded={expanded}
                >
                    {expanded ? 'Hide details' : 'View details'}
                </button>
            </div>
            {expanded && (
                <p className="mt-3 text-sm whitespace-pre-line text-gray-600">
                    {announcement.content}
                </p>
            )}
        </article>
    );
}

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
            className={`flex items-center justify-between gap-4 px-4 py-4 transition hover:bg-gray-50 ${!last ? 'border-b border-gray-100' : ''}`}
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
            <span className="shrink-0 text-[10px] text-gray-400">{time}</span>
        </Link>
    );
}

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
            <p className="text-xs font-medium tracking-wide text-gray-400 uppercase">
                {title}
            </p>
            <p className="mt-3 text-3xl font-semibold text-gray-800">{value}</p>
            <p className="mt-1 text-xs text-gray-500">{subtitle}</p>
        </Link>
    );
}
