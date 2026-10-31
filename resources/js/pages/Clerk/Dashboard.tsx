import { Deferred, Head, Link } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import { formatAnnouncementStatusLabel, isEventAnnouncement } from '@/components/announcements/announcement';

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

    if (announcement.approval_status === 'approved' && !announcement.is_published) {
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

            <div className="space-y-6 max-w-7xl mx-auto">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-xl md:text-2xl font-bold text-gray-800">
                            Welcome, {displayName}
                        </h1>
                        <p className="text-sm text-gray-500">
                            Overview of your barangay's residents, updates, and programs.
                        </p>
                    </div>
                </div>

                <Deferred
                    data={['stats', 'recentAnnouncements']}
                    fallback={<PortalPageSkeleton metrics={3} rows={5} list />}
                >
                {/* REAL STATS */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="bg-white border-l-4 border-[#3f6f3d] rounded-r-lg shadow-sm p-4 sm:p-5">
                        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Active Residents
                        </h3>
                        <p className="text-2xl sm:text-3xl font-bold text-gray-800">
                            {stats?.totalResidents ?? 0}
                        </p>
                    </div>
                    <div className="bg-white border-l-4 border-green-500 rounded-r-lg shadow-sm p-4 sm:p-5">
                        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Published Updates & Programs
                        </h3>
                        <p className="text-2xl sm:text-3xl font-bold text-gray-800">
                            {stats?.approvedAnnouncements ?? 0}
                        </p>
                    </div>
                    <div className="bg-white border-l-4 border-amber-500 rounded-r-lg shadow-sm p-4 sm:p-5">
                        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                            Draft Programs
                        </h3>
                        <p className="text-2xl sm:text-3xl font-bold text-gray-800">
                            {stats?.draftPrograms ?? 0}
                        </p>
                    </div>
                </div>

                {/* RECENT ANNOUNCEMENTS */}
                <div className="bg-white border border-[#e6ddcd] rounded-lg shadow-sm overflow-hidden">
                    <div className="flex items-center justify-between px-4 sm:px-6 py-4 border-b border-[#e6ddcd]">
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wider">
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
                        <p className="px-4 sm:px-6 py-8 text-center text-sm text-gray-500">
                            No updates or programs yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[600px] text-left border-collapse">
                                <thead>
                                    <tr className="bg-[#f0ebe1] border-b border-[#e6ddcd] text-xs text-[#8a7f6a] uppercase tracking-wider">
                                        <th className="px-4 sm:px-6 py-3 font-medium">Title</th>
                                        <th className="px-4 sm:px-6 py-3 font-medium">Category</th>
                                        <th className="px-4 sm:px-6 py-3 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 text-sm">
                                    {recentAnnouncements.map((announcement) => (
                                        <tr key={announcement.id} className="hover:bg-gray-50 transition-colors">
                                            <td className="px-4 sm:px-6 py-4 font-medium text-gray-900">
                                                {announcement.title}
                                            </td>
                                            <td className="px-4 sm:px-6 py-4 text-gray-600 whitespace-nowrap">
                                                {announcement.category || '—'}
                                            </td>
                                            <td className="px-4 sm:px-6 py-4 whitespace-nowrap">
                                                <span
                                                    className={`px-3 py-1 rounded-full text-xs font-semibold border ${
                                                        STATUS_STYLES[announcement.approval_status] ??
                                                        'bg-gray-50 text-gray-600 border-gray-200'
                                                    }`}
                                                >
                                                    {statusLabel(announcement)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
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
