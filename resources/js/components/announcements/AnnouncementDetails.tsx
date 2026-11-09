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
                className="inline-flex text-xs font-semibold text-brand hover:text-brand-deep"
            >
                Back to announcements
            </Link>

            <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-6">
                <div className="flex flex-col gap-4 border-b border-line-soft pb-5 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span
                                className={`inline-flex rounded px-2.5 py-1 text-[10px] font-semibold tracking-wider uppercase ${
                                    isEvent
                                        ? 'bg-purple-100 text-purple-800'
                                        : 'bg-brand-soft text-brand'
                                }`}
                            >
                                {isEvent ? 'Event' : 'Informational Update'}
                            </span>

                            {showImportance && (
                                <AnnouncementImportanceBadge
                                    importance={announcement.importance}
                                />
                            )}

                            <span className="inline-flex rounded bg-secondary px-2.5 py-1 text-[10px] font-semibold tracking-wider text-ink-muted uppercase">
                                {formatCategoryLabel(announcement.category)}
                            </span>

                            <span className="inline-flex rounded bg-secondary px-2.5 py-1 text-[10px] font-semibold tracking-wider text-ink-muted uppercase">
                                {formatAudienceLabel(
                                    announcement.target_audience,
                                )}
                            </span>
                        </div>

                        <h1 className="text-xl font-semibold text-ink-strong sm:text-2xl">
                            {announcement.title}
                        </h1>

                        <p className="mt-2 text-xs text-ink-muted">
                            Posted {formatDateTime(announcement.created_at)}
                            {announcement.author?.name
                                ? ` by ${announcement.author.name}`
                                : ''}
                        </p>
                    </div>

                    {showApprovalStatus && announcement.approval_status && (
                        <span
                            className={`inline-flex w-fit rounded px-2.5 py-1 text-xs font-semibold uppercase ${approvalStatusStyle(
                                announcement.approval_status,
                            )}`}
                        >
                            {formatAnnouncementStatusLabel(announcement)}
                        </span>
                    )}
                </div>

                {showApprovalStatus &&
                    !isEvent &&
                    announcement.rejection_reason && (
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
                        <p className="text-sm leading-relaxed whitespace-pre-line text-ink">
                            {announcement.content}
                        </p>

                        {isEvent &&
                            (announcement.event_start_at ||
                                announcement.location) && (
                                <div className="mt-6 rounded-lg border border-line-soft bg-canvas p-4">
                                    <h2 className="text-xs font-semibold tracking-wide text-brand-deep uppercase">
                                        Event details
                                    </h2>

                                    <dl className="mt-3 space-y-2 text-sm text-ink">
                                        {announcement.event_start_at && (
                                            <div className="flex gap-2">
                                                <dt className="w-20 shrink-0 font-semibold text-ink-muted">
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
                                                <dt className="w-20 shrink-0 font-semibold text-ink-muted">
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
                        <div className="rounded-lg border border-line-soft bg-canvas p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-ink-muted uppercase">
                                Announcement information
                            </h2>

                            <dl className="mt-3 space-y-3 text-xs">
                                <div>
                                    <dt className="font-semibold text-ink-muted">
                                        Type
                                    </dt>
                                    <dd className="mt-0.5 text-ink-strong">
                                        {isEvent
                                            ? 'Event'
                                            : 'Informational Update'}
                                    </dd>
                                </div>

                                <div>
                                    <dt className="font-semibold text-ink-muted">
                                        Category
                                    </dt>
                                    <dd className="mt-0.5 text-ink-strong">
                                        {formatCategoryLabel(
                                            announcement.category,
                                        )}
                                    </dd>
                                </div>

                                <div>
                                    <dt className="font-semibold text-ink-muted">
                                        Audience
                                    </dt>
                                    <dd className="mt-0.5 text-ink-strong">
                                        {formatAudienceLabel(
                                            announcement.target_audience,
                                        )}
                                    </dd>
                                </div>

                                {showAttendanceSetting && isEvent && (
                                    <div>
                                        <dt className="font-semibold text-ink-muted">
                                            Attendance
                                        </dt>
                                        <dd className="mt-0.5 text-ink-strong">
                                            {formatAttendanceSetting(
                                                announcement,
                                            )}
                                        </dd>
                                    </div>
                                )}

                                {announcement.author?.name && (
                                    <div>
                                        <dt className="font-semibold text-ink-muted">
                                            Posted by
                                        </dt>
                                        <dd className="mt-0.5 text-ink-strong">
                                            {announcement.author.name}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </div>

                        {showCompletion && announcement.completed_at && (
                            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-800">
                                <p className="font-semibold uppercase">Done</p>
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
                                className="inline-flex w-full items-center justify-center rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-brand-deep"
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
