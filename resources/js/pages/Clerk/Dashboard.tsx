import { Deferred, Head, Link } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import {
    formatAnnouncementStatusLabel,
    isEventAnnouncement,
} from '@/components/announcements/announcement';

interface User {
    id: number;
    name: string;
    email: string;
    staffAssignments?: { department: string; position: string }[];
}

interface DashboardStats {
    totalResidents: number;
    totalAnnouncements: number;
    pendingAnnouncements: number;
    draftPrograms?: number;
    approvedAnnouncements: number;
}

interface RecentAnnouncement {
    id: number;
    title: string;
    category: string | null;
    approval_status: string;
    program_status?: string | null;
    requires_attendance?: boolean;
    is_published: boolean;
    created_at: string | null;
}

interface DashboardProps {
    auth: { user: User };
    userName?: string;
    stats?: DashboardStats;
    recentAnnouncements?: RecentAnnouncement[];
}

const STATUS_STYLES: Record<string, string> = {
    approved: 'bg-[#e8f1e4] text-[#3f6f3d] border-[#b8d6bf]',
    pending: 'bg-amber-50 text-amber-700 border-amber-200',
    rejected: 'bg-red-50 text-red-700 border-red-200',
};

function statusLabel(announcement: RecentAnnouncement): string {
    if (isEventAnnouncement(announcement)) {
        return formatAnnouncementStatusLabel(announcement);
    }

    if (
        announcement.approval_status === 'approved' &&
        !announcement.is_published
    ) {
        return 'approved · unpublished';
    }

    return announcement.approval_status;
}

export default function Dashboard({
    auth,
    userName,
    stats,
    recentAnnouncements = [],
}: DashboardProps) {
    const displayName = userName || auth?.user?.name || 'Clerk Staff';

    return (
        <ClerkLayout user={auth.user} active="overview">
            <Head title="Overview" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <h1 className="text-xl font-bold text-gray-800 md:text-2xl">
                            Welcome, {displayName}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Overview of your barangay's residents, updates, and
                            programs.
                        </p>
                    </div>
                </div>

                <Deferred
                    data={['stats', 'recentAnnouncements']}
                    fallback={<PortalPageSkeleton metrics={3} rows={5} list />}
                >
                    {/* REAL STATS */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="rounded-r-lg border-l-4 border-[#3f6f3d] bg-white p-4 shadow-sm sm:p-5">
                            <h3 className="mb-1 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                Active Residents
                            </h3>
                            <p className="text-2xl font-bold text-gray-800 sm:text-3xl">
                                {stats?.totalResidents ?? 0}
                            </p>
                        </div>
                        <div className="rounded-r-lg border-l-4 border-green-500 bg-white p-4 shadow-sm sm:p-5">
                            <h3 className="mb-1 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                Published Updates & Programs
                            </h3>
                            <p className="text-2xl font-bold text-gray-800 sm:text-3xl">
                                {stats?.approvedAnnouncements ?? 0}
                            </p>
                        </div>
                        <div className="rounded-r-lg border-l-4 border-amber-500 bg-white p-4 shadow-sm sm:p-5">
                            <h3 className="mb-1 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                Draft Programs
                            </h3>
                            <p className="text-2xl font-bold text-gray-800 sm:text-3xl">
                                {stats?.draftPrograms ?? 0}
                            </p>
                        </div>
                    </div>

                    {/* RECENT ANNOUNCEMENTS */}
                    <div className="overflow-hidden rounded-lg border border-[#e6ddcd] bg-white shadow-sm">
                        <div className="flex items-center justify-between border-b border-[#e6ddcd] px-4 py-4 sm:px-6">
                            <h2 className="text-sm font-semibold tracking-wider text-gray-700 uppercase">
                                Recent Updates & Programs
                            </h2>
                            <Link
                                href="/clerk/updates"
                                className="text-xs font-medium text-[#3f6f3d] hover:text-[#315b30]"
                            >
                                Manage updates &rarr;
                            </Link>
                        </div>

                        {recentAnnouncements.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-gray-500 sm:px-6">
                                No updates or programs yet.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[600px] border-collapse text-left">
                                    <thead>
                                        <tr className="border-b border-[#e6ddcd] bg-[#f0ebe1] text-xs tracking-wider text-[#8a7f6a] uppercase">
                                            <th className="px-4 py-3 font-medium sm:px-6">
                                                Title
                                            </th>
                                            <th className="px-4 py-3 font-medium sm:px-6">
                                                Category
                                            </th>
                                            <th className="px-4 py-3 font-medium sm:px-6">
                                                Status
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 text-sm">
                                        {recentAnnouncements.map(
                                            (announcement) => (
                                                <tr
                                                    key={announcement.id}
                                                    className="transition-colors hover:bg-gray-50"
                                                >
                                                    <td className="px-4 py-4 font-medium text-gray-900 sm:px-6">
                                                        {announcement.title}
                                                    </td>
                                                    <td className="px-4 py-4 whitespace-nowrap text-gray-600 sm:px-6">
                                                        {announcement.category ||
                                                            '—'}
                                                    </td>
                                                    <td className="px-4 py-4 whitespace-nowrap sm:px-6">
                                                        <span
                                                            className={`rounded-full border px-3 py-1 text-xs font-semibold ${
                                                                STATUS_STYLES[
                                                                    announcement
                                                                        .approval_status
                                                                ] ??
                                                                'border-gray-200 bg-gray-50 text-gray-600'
                                                            }`}
                                                        >
                                                            {statusLabel(
                                                                announcement,
                                                            )}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </Deferred>
            </div>
        </ClerkLayout>
    );
}
