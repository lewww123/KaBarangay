import {
    formatAttendanceReviewLabel,
    formatAudienceLabel,
} from '@/components/announcements/announcement';

interface AnnouncementReviewSummaryProps {
    type: 'event' | 'informational';
    audience: string;
    requiresAttendance: boolean;
    willResubmit?: boolean;
    directPublish?: boolean;
}

export default function AnnouncementReviewSummary({
    type,
    audience,
    requiresAttendance,
    willResubmit = false,
    directPublish = false,
}: AnnouncementReviewSummaryProps) {
    const isEvent = type === 'event';
    const attendanceLabel = isEvent
        ? formatAttendanceReviewLabel(requiresAttendance)
        : 'Not applicable - informational updates do not use attendance sheets';
    const approvalLabel = directPublish
        ? 'Publish directly to the resident portal, or save a program as draft.'
        : willResubmit
          ? 'Saving changes returns this announcement to pending review.'
          : 'Admin approval is required before this announcement is published.';

    return (
        <div className="rounded-lg border border-[#dfe4ea] bg-surface p-3">
            <h3 className="text-[10px] font-semibold tracking-wide text-brand-deep uppercase">
                Review
            </h3>
            <dl className="mt-2 space-y-1.5 text-xs">
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-ink-muted">
                        Type
                    </dt>
                    <dd className="text-ink-strong">
                        {isEvent ? 'Event' : 'Informational Update'}
                    </dd>
                </div>
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-ink-muted">
                        Audience
                    </dt>
                    <dd className="text-ink-strong">
                        {formatAudienceLabel(audience)}
                    </dd>
                </div>
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-ink-muted">
                        Attendance
                    </dt>
                    <dd className="text-ink-strong">{attendanceLabel}</dd>
                </div>
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-ink-muted">
                        Publication
                    </dt>
                    <dd className="text-ink-strong">{approvalLabel}</dd>
                </div>
            </dl>
        </div>
    );
}
