import { Head, Link } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';

interface Announcement {
    id: number;
    title: string;
    category: string;
    approval_status: 'pending' | 'approved' | 'rejected';
    requires_attendance: boolean;
    created_at: string;
}

interface Props {
    userName: string;
    stats: {
        totalResidents: number;
        totalAnnouncements: number;
        pendingAnnouncements: number;
        approvedAnnouncements: number;
    };
    recentAnnouncements: Announcement[];
}

export default function PwdDashboard({ userName, stats, recentAnnouncements = [] }: Props) {
    return (
        <PwdLayout active="dashboard">
            <Head title="PWD Sector Desk Portal" />

            <div className="mx-auto max-w-7xl space-y-6 font-sans p-2 sm:p-4">
                {/* WELCOME BANNER */}
                <div className="rounded-2xl border border-blue-200 bg-blue-50/60 p-6 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <span className="inline-flex items-center gap-1 rounded-md bg-blue-800 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-white">
                                ♿ PWD Sector Desk
                            </span>
                            <h1 className="mt-2 text-2xl font-extrabold text-gray-900 sm:text-3xl">
                                Welcome, {userName}!
                            </h1>
                            <p className="mt-1 text-sm text-gray-600">
                                Post PWD sector announcements, monitor admin review, and record distribution QR attendance.
                            </p>
                        </div>

                        <Link
                            href="/pwd/announcements"
                            className="inline-flex items-center gap-2 rounded-xl bg-blue-800 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-blue-900"
                        >
                            + Post PWD Announcement
                        </Link>
                    </div>
                </div>

                {/* STATS OVERVIEW */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <span className="text-xs font-bold uppercase tracking-wider text-gray-400">
                            Registered PWD Residents
                        </span>
                        <p className="mt-2 text-3xl font-extrabold text-gray-900">
                            {stats.totalResidents}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <span className="text-xs font-bold uppercase tracking-wider text-gray-400">
                            Total Sector Announcements
                        </span>
                        <p className="mt-2 text-3xl font-extrabold text-gray-900">
                            {stats.totalAnnouncements}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-amber-200 bg-amber-50/50 p-5 shadow-sm">
                        <span className="text-xs font-bold uppercase tracking-wider text-amber-800">
                            Pending Approval
                        </span>
                        <p className="mt-2 text-3xl font-extrabold text-amber-900">
                            {stats.pendingAnnouncements}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-5 shadow-sm">
                        <span className="text-xs font-bold uppercase tracking-wider text-emerald-700">
                            Approved & Published
                        </span>
                        <p className="mt-2 text-3xl font-extrabold text-emerald-800">
                            {stats.approvedAnnouncements}
                        </p>
                    </div>
                </div>

                {/* RECENT ANNOUNCEMENTS TABLE */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="text-lg font-bold text-gray-900">
                                PWD Sector Announcements & Events
                            </h2>
                            <p className="text-xs text-gray-500">
                                Recent updates submitted for PWD sector residents.
                            </p>
                        </div>
                        <Link
                            href="/pwd/announcements"
                            className="text-xs font-bold text-blue-800 hover:underline"
                        >
                            View All →
                        </Link>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-gray-600">
                            <thead className="bg-gray-50 text-xs font-semibold uppercase text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th className="px-4 py-3">Title</th>
                                    <th className="px-4 py-3">Category</th>
                                    <th className="px-4 py-3">Approval Status</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {recentAnnouncements.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="p-8 text-center text-sm text-gray-500">
                                            No announcements posted yet for PWD sector.
                                        </td>
                                    </tr>
                                ) : (
                                    recentAnnouncements.map((item) => (
                                        <tr key={item.id} className="hover:bg-gray-50/80 transition">
                                            <td className="px-4 py-3.5 font-semibold text-gray-900">
                                                {item.title}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span className="rounded-md bg-blue-100 px-2.5 py-1 text-xs font-bold uppercase text-blue-800">
                                                    {item.category}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span className={`rounded-md px-2.5 py-1 text-xs font-bold uppercase ${
                                                    item.approval_status === 'approved'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : item.approval_status === 'rejected'
                                                        ? 'bg-red-100 text-red-800'
                                                        : 'bg-amber-100 text-amber-800'
                                                }`}>
                                                    {item.approval_status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3.5 text-right">
                                                {item.approval_status === 'approved' && item.requires_attendance ? (
                                                    <Link
                                                        href={`/pwd/announcements/${item.id}/attendance`}
                                                        className="inline-flex rounded-lg bg-blue-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-900 transition"
                                                    >
                                                        QR Attendance Sheet
                                                    </Link>
                                                ) : (
                                                    <span className="text-xs text-gray-400">—</span>
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