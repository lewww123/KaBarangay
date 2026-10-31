import { Link } from '@inertiajs/react';
import AnnouncementImportanceBadge from '@/components/announcements/AnnouncementImportanceBadge';
import {
    formatAnnouncementStatusLabel,
    formatAttendanceSetting,
    formatAudienceLabel,
    isEventAnnouncement,
} from '@/components/announcements/announcement';

export interface AnnouncementDetailsRecord {
    id: number;
    title: string;
    content: string;
    category: string;
    importance?: string | null;
    target_audience: string;
    type?: 'event' | 'informational';
    approval_status?: 'pending' | 'approved' | 'rejected';
    program_status?: 'draft' | 'ongoing' | 'done' | null;
    is_published?: boolean;
    rejection_reason?: string | null;
    requires_attendance?: boolean;
    attendance_available?: boolean;
    event_start_at?: string | null;
    location?: string | null;
    completed_at?: string | null;
    completed_by?: number | null;
    completer?: { name: string } | null;
    created_at: string;
    author?: { name: string } | null;
}

interface AnnouncementDetailsProps {
    announcement: AnnouncementDetailsRecord;
    backHref: string;
    attendanceHref?: string | null;
    showApprovalStatus?: boolean;
    showAttendanceSetting?: boolean;
    showImportance?: boolean;
    showCompletion?: boolean;
}

export default function AnnouncementDetails({
    announcement,
    backHref,
    attendanceHref,
    showApprovalStatus = false,
    showAttendanceSetting = false,
    showImportance = false,
    showCompletion = false,
}: AnnouncementDetailsProps) {
    const isEvent = isEventAnnouncement(announcement);

    return (
        <div className="space-y-5">
            <Link
                href={backHref}
                className="inline-flex text-xs font-semibold text-[#3f6f3d] hover:text-[#315b30]"
            >
                Back to announcements
            </Link>

            <div className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div className="flex flex-col gap-4 border-b border-gray-100 pb-5 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span
                                className={`inline-flex rounded px-2.5 py-1 text-[10px] font-bold tracking-wider uppercase ${
                                    isEvent
                                        ? 'bg-purple-100 text-purple-800'
                                        : 'bg-[#e8f1e4] text-[#3f6f3d]'
                                }`}
                            >
                                {isEvent ? 'Event' : 'Informational Update'}
                            </span>

                            {showImportance && (
                                <AnnouncementImportanceBadge
                                    importance={announcement.importance}
                                />
                            )}

                            <span className="inline-flex rounded bg-gray-100 px-2.5 py-1 text-[10px] font-bold tracking-wider text-gray-600 uppercase">
                                {formatCategoryLabel(announcement.category)}
                            </span>

                            <span className="inline-flex rounded bg-gray-100 px-2.5 py-1 text-[10px] font-bold tracking-wider text-gray-600 uppercase">
                                {formatAudienceLabel(
                                    announcement.target_audience,
                                )}
                            </span>
                        </div>

                        <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                            {announcement.title}
                        </h1>

                        <p className="mt-2 text-xs text-gray-500">
                            Posted {formatDateTime(announcement.created_at)}
                            {announcement.author?.name
                                ? ` by ${announcement.author.name}`
                                : ''}
                        </p>
                    </div>

                    {showApprovalStatus && announcement.approval_status && (
                        <span
                            className={`inline-flex w-fit rounded px-2.5 py-1 text-xs font-bold uppercase ${approvalStatusStyle(
                                announcement.approval_status,
                            )}`}
                        >
                            {formatAnnouncementStatusLabel(announcement)}
                        </span>
                    )}
                </div>

                {showApprovalStatus && !isEvent && announcement.rejection_reason && (
                    <div
                        className={`mt-5 rounded border p-3 text-xs ${
                            announcement.approval_status === 'rejected'
                                ? 'border-red-200 bg-red-50 text-red-700'
                                : 'border-amber-200 bg-amber-50 text-amber-700'
                        }`}
                    >
                        <p className="font-semibold">
                            {announcement.approval_status === 'rejected'
                                ? 'Returned for revision'
                                : 'Previous review note'}
                        </p>
                        <p className="mt-1 whitespace-pre-line">
                            {announcement.rejection_reason}
                        </p>
                    </div>
                )}

                <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_16rem]">
                    <div>
                        <p className="text-sm leading-relaxed whitespace-pre-line text-gray-700">
                            {announcement.content}
                        </p>

                        {isEvent &&
                            (announcement.event_start_at ||
                                announcement.location) && (
                                <div className="mt-6 rounded-lg border border-[#d6e3d2] bg-[#f7faf6] p-4">
                                    <h2 className="text-xs font-bold tracking-wide text-[#315b30] uppercase">
                                        Event details
                                    </h2>

                                    <dl className="mt-3 space-y-2 text-sm text-gray-700">
                                        {announcement.event_start_at && (
                                            <div className="flex gap-2">
                                                <dt className="w-20 shrink-0 font-semibold text-gray-500">
                                                    Schedule
                                                </dt>
                                                <dd>
                                                    {formatDateTime(
                                                        announcement.event_start_at,
                                                    )}
                                                </dd>
                                            </div>
                                        )}

                                        {announcement.location && (
                                            <div className="flex gap-2">
                                                <dt className="w-20 shrink-0 font-semibold text-gray-500">
                                                    Venue
                                                </dt>
                                                <dd>{announcement.location}</dd>
                                            </div>
                                        )}
                                    </dl>
                                </div>
                            )}
                    </div>

                    <aside className="space-y-4">
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <h2 className="text-xs font-bold tracking-wide text-gray-500 uppercase">
                                Announcement information
                            </h2>

                            <dl className="mt-3 space-y-3 text-xs">
                                <div>
                                    <dt className="font-semibold text-gray-500">
                                        Type
                                    </dt>
                                    <dd className="mt-0.5 text-gray-800">
                                        {isEvent
                                            ? 'Event'
                                            : 'Informational Update'}
                                    </dd>
                                </div>

                                <div>
                                    <dt className="font-semibold text-gray-500">
                                        Category
                                    </dt>
                                    <dd className="mt-0.5 text-gray-800">
                                        {formatCategoryLabel(
                                            announcement.category,
                                        )}
                                    </dd>
                                </div>

                                <div>
                                    <dt className="font-semibold text-gray-500">
                                        Audience
                                    </dt>
                                    <dd className="mt-0.5 text-gray-800">
                                        {formatAudienceLabel(
                                            announcement.target_audience,
                                        )}
                                    </dd>
                                </div>

                                {showAttendanceSetting && isEvent && (
                                    <div>
                                        <dt className="font-semibold text-gray-500">
                                            Attendance
                                        </dt>
                                        <dd className="mt-0.5 text-gray-800">
                                            {formatAttendanceSetting(
                                                announcement,
                                            )}
                                        </dd>
                                    </div>
                                )}

                                {announcement.author?.name && (
                                    <div>
                                        <dt className="font-semibold text-gray-500">
                                            Posted by
                                        </dt>
                                        <dd className="mt-0.5 text-gray-800">
                                            {announcement.author.name}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </div>

                        {showCompletion && announcement.completed_at && (
                            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800">
                                <p className="font-bold uppercase">Done</p>
                                <p className="mt-1">
                                    Completed{' '}
                                    {formatDateTime(announcement.completed_at)}
                                </p>
                                {announcement.completer?.name && (
                                    <p className="mt-1">
                                        Marked by {announcement.completer.name}
                                    </p>
                                )}
                            </div>
                        )}

                        {attendanceHref && (
                            <Link
                                href={attendanceHref}
                                className="inline-flex w-full items-center justify-center rounded-md bg-[#3f6f3d] px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-[#315b30]"
                            >
                                Open attendance sheet
                            </Link>
                        )}
                    </aside>
                </div>
            </div>
        </div>
    );
}

function approvalStatusStyle(status: string) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-red-100 text-red-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function formatCategoryLabel(category: string) {
    if (!category) {
        return 'General';
    }

    return category.replace(/_/g, ' ');
}

function formatDateTime(dateString: string) {
    if (!dateString) {
        return '';
    }

    const date = new Date(dateString);

    if (Number.isNaN(date.getTime())) {
        return dateString;
    }

    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}
