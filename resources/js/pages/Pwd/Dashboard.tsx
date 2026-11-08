import withDeferredPage from '@/components/withDeferredPage';
import { Head, Link } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import {
    formatAnnouncementStatusLabel,
    isAttendanceAvailable,
} from '@/components/announcements/announcement';

interface Announcement {
    id: number;
    title: string;
    category: string;
    approval_status: 'pending' | 'approved' | 'rejected';
    program_status?: 'draft' | 'ongoing' | 'done' | null;
    requires_attendance: boolean;
    created_at: string;
}

interface Props {
    userName: string;
    stats: {
        totalResidents: number;
        totalAnnouncements: number;
        pendingAnnouncements: number;
        draftPrograms?: number;
        approvedAnnouncements: number;
    };
    recentAnnouncements: Announcement[];
}

export default withDeferredPage(
    PwdDashboard,
    ['stats', 'recentAnnouncements'],
    () => (
        <PwdLayout active={'dashboard'}>
            <PortalPageSkeleton metrics={4} rows={5} />
        </PwdLayout>
    ),
);

function PwdDashboard({
    userName,
    stats,
    recentAnnouncements,
}: Props) {
    return (
        <PwdLayout active="dashboard">
            <Head title="PWD Sector Desk Portal" />

            <div className="mx-auto max-w-7xl space-y-6 p-2 font-sans sm:p-4">
                {/* WELCOME BANNER */}
                <div className="rounded-xl border border-blue-200 bg-blue-50/60 p-6 shadow-card">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <span className="inline-flex items-center gap-1 rounded-lg bg-blue-800 px-2.5 py-1 text-xs font-semibold tracking-wider text-white uppercase">
                                PWD Sector Desk
                            </span>
                            <h1 className="mt-2 text-2xl font-semibold text-ink-strong sm:text-3xl">
                                Welcome, {userName}!
                            </h1>
                            <p className="mt-1 text-sm text-ink-muted">
                                Review sector applications, publish updates,
                                manage programs, and record attendance.
                            </p>
                        </div>

                        <Link
                            href="/pwd/updates"
                            className="inline-flex items-center gap-2 rounded-xl bg-blue-800 px-4 py-2.5 text-xs font-semibold text-white shadow-card transition hover:bg-blue-900"
                        >
                            View Updates
                        </Link>
                    </div>
                </div>


                    {/* STATS OVERVIEW */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card">
                            <span className="text-xs font-semibold tracking-wider text-ink-faint uppercase">
                                Registered PWD Residents
                            </span>
                            <p className="mt-2 text-3xl font-semibold text-ink-strong">
                                {stats.totalResidents}
                            </p>
                        </div>

                        <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card">
                            <span className="text-xs font-semibold tracking-wider text-ink-faint uppercase">
                                Updates & Programs
                            </span>
                            <p className="mt-2 text-3xl font-semibold text-ink-strong">
                                {stats.totalAnnouncements}
                            </p>
                        </div>

                        <div className="rounded-xl border border-amber-200 bg-amber-50/50 p-5 shadow-card">
                            <span className="text-xs font-semibold tracking-wider text-amber-800 uppercase">
                                Draft Programs
                            </span>
                            <p className="mt-2 text-3xl font-semibold text-amber-900">
                                {stats.draftPrograms ?? 0}
                            </p>
                        </div>

                        <div className="rounded-xl border border-emerald-200 bg-emerald-50/50 p-5 shadow-card">
                            <span className="text-xs font-semibold tracking-wider text-emerald-700 uppercase">
                                Published Updates & Programs
                            </span>
                            <p className="mt-2 text-3xl font-semibold text-emerald-800">
                                {stats.approvedAnnouncements}
                            </p>
                        </div>
                    </div>

                    {/* RECENT ANNOUNCEMENTS TABLE */}
                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-6 shadow-card">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold text-ink-strong">
                                    PWD Sector Updates & Programs
                                </h2>
                                <p className="text-xs text-ink-muted">
                                    Recent updates and programs for PWD sector
                                    residents.
                                </p>
                            </div>
                            <Link
                                href="/pwd/updates"
                                className="text-xs font-semibold text-blue-800 hover:underline"
                            >
                                View All 
                            </Link>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-ink-muted">
                                <thead className="border-b border-line-soft bg-canvas text-xs font-semibold text-ink-muted uppercase">
                                    <tr>
                                        <th className="px-4 py-3">Title</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">
                                            Approval Status
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {recentAnnouncements.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="p-8 text-center text-sm text-ink-muted"
                                            >
                                                No updates or programs posted
                                                yet for the PWD sector.
                                            </td>
                                        </tr>
                                    ) : (
                                        recentAnnouncements.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="transition hover:bg-canvas/80"
                                            >
                                                <td className="px-4 py-3.5 font-semibold text-ink-strong">
                                                    {item.title}
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <span className="rounded-lg bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 uppercase">
                                                        {item.category}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3.5">
                                                    <span
                                                        className={`rounded-lg px-2.5 py-1 text-xs font-semibold uppercase ${
                                                            item.approval_status ===
                                                            'approved'
                                                                ? 'bg-emerald-100 text-emerald-800'
                                                                : item.approval_status ===
                                                                    'rejected'
                                                                  ? 'bg-red-100 text-red-800'
                                                                  : 'bg-amber-100 text-amber-800'
                                                        }`}
                                                    >
                                                        {formatAnnouncementStatusLabel(
                                                            item,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3.5 text-right">
                                                    {isAttendanceAvailable(
                                                        item,
                                                    ) ? (
                                                        <Link
                                                            href={`/pwd/announcements/${item.id}/attendance`}
                                                            className="inline-flex rounded-lg bg-blue-800 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-900"
                                                        >
                                                            QR Attendance Sheet
                                                        </Link>
                                                    ) : (
                                                        <span className="text-xs text-ink-faint">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

            </div>
        </PwdLayout>
    );
}
