export type AnnouncementPortal = 'clerk' | 'pwd' | 'senior';

export type AnnouncementImportance = 'low' | 'normal' | 'high' | 'urgent';

export type AnnouncementTab =
    | 'all'
    | 'ongoing'
    | 'draft'
    | 'done';

export type AnnouncementTabCounts = Record<AnnouncementTab, number>;

export const ANNOUNCEMENT_TABS: Array<{
    value: AnnouncementTab;
    label: string;
}> = [
    { value: 'all', label: 'All' },
    { value: 'ongoing', label: 'Ongoing' },
    { value: 'draft', label: 'Draft' },
    { value: 'done', label: 'Done' },
];

export const ANNOUNCEMENT_IMPORTANCE_OPTIONS: Array<{
    value: AnnouncementImportance;
    label: string;
}> = [
    { value: 'low', label: 'Low' },
    { value: 'normal', label: 'Normal' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
];

export interface AnnouncementEligibilityRecord {
    program_status?: string | null;
    approval_status?: string | null;
    category?: string | null;
    type?: string | null;
    requires_attendance?: unknown;
    attendance_available?: unknown;
}

export function toBoolean(value: unknown): boolean {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value === 1;
    }

    if (typeof value === 'string') {
        return ['1', 'true', 'on', 'yes'].includes(value.toLowerCase());
    }

    return false;
}

export function isEventAnnouncement(
    record: AnnouncementEligibilityRecord,
): boolean {
    return (
        record.type === 'event' ||
        record.category === 'Event' ||
        toBoolean(record.requires_attendance)
    );
}

export function canMarkAnnouncementDone(
    record: AnnouncementEligibilityRecord & {
        approval_status?: string | null;
        completed_at?: string | null;
    },
): boolean {
    return (
        !record.completed_at &&
        record.program_status !== 'draft' &&
        record.approval_status === 'approved' &&
        isEventAnnouncement(record)
    );
}

export function isAttendanceAvailable(
    record: AnnouncementEligibilityRecord,
): boolean {
    if (record.approval_status !== 'approved' || record.program_status === 'draft') {
        return false;
    }

    if (
        record.attendance_available !== undefined &&
        record.attendance_available !== null
    ) {
        return toBoolean(record.attendance_available);
    }

    return toBoolean(record.requires_attendance);
}

export function attendanceUrl(
    portal: AnnouncementPortal,
    announcementId: number,
): string {
    return `/${portal}/announcements/${announcementId}/attendance`;
}

export function formatAnnouncementType(
    record: AnnouncementEligibilityRecord,
): string {
    return isEventAnnouncement(record) ? 'Event' : 'Informational Update';
}

export function formatApprovalStatusLabel(status?: string | null): string {
    if (!status) {
        return 'Unknown';
    }

    return status.charAt(0).toUpperCase() + status.slice(1);
}

export function formatAnnouncementStatusLabel(record: AnnouncementEligibilityRecord & { completed_at?: string | null; is_published?: boolean }): string {
    if (isEventAnnouncement(record)) {
        return formatApprovalStatusLabel(record.program_status ?? (record.completed_at ? 'done' : record.approval_status === 'approved' && record.is_published ? 'ongoing' : 'draft'));
    }

    return formatApprovalStatusLabel(record.approval_status);
}

export function formatAnnouncementPostedAt(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}

export function normalizeAnnouncementImportance(
    importance?: string | null,
): AnnouncementImportance {
    return ['low', 'normal', 'high', 'urgent'].includes(importance ?? '')
        ? (importance as AnnouncementImportance)
        : 'normal';
}

export function formatImportanceLabel(importance?: string | null): string {
    const normalized = normalizeAnnouncementImportance(importance);

    return (
        ANNOUNCEMENT_IMPORTANCE_OPTIONS.find(
            (option) => option.value === normalized,
        )?.label ?? 'Normal'
    );
}

export function importanceBadgeClass(importance?: string | null): string {
    switch (normalizeAnnouncementImportance(importance)) {
        case 'urgent':
            return 'border-red-200 bg-red-50 text-red-700';
        case 'high':
            return 'border-amber-200 bg-amber-50 text-amber-700';
        case 'low':
            return 'border-gray-200 bg-gray-50 text-gray-600';
        default:
            return 'border-slate-200 bg-slate-50 text-slate-700';
    }
}

export function completionUrl(
    portal: AnnouncementPortal,
    announcementId: number,
    kind: 'event' | 'update' = 'event',
): string {
    const resource = kind === 'update' ? 'updates' : 'announcements';

    return `/${portal}/${resource}/${announcementId}/complete`;
}

export function formatAudienceLabel(audience?: string | null): string {
    switch (audience) {
        case 'all':
            return 'All Residents';
        case 'senior_citizens':
            return 'Senior Citizens';
        case 'pwd':
            return 'PWD';
        default:
            return audience
                ? audience
                      .replace(/_/g, ' ')
                      .replace(/\b\w/g, (character) => character.toUpperCase())
                : 'Unspecified';
    }
}

export function formatAttendanceReviewLabel(
    requiresAttendance: unknown,
): string {
    return toBoolean(requiresAttendance)
        ? 'Enabled - available when published'
        : 'Not enabled - no attendance sheet';
}

export function formatAttendanceSetting(
    record: AnnouncementEligibilityRecord,
): string {
    if (!isEventAnnouncement(record)) {
        return 'Not applicable - informational updates do not use attendance sheets';
    }

    if (!toBoolean(record.requires_attendance)) {
        return 'Not enabled - no attendance sheet';
    }

    if (isAttendanceAvailable(record)) {
        return 'Enabled - attendance sheet available';
    }

    if (record.approval_status === 'rejected') {
        return 'Enabled - available when published';
    }

    return 'Enabled - available when published';
}
