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
        <div className="rounded-md border border-[#d6e3d2] bg-[#f7faf6] p-3">
            <h3 className="text-[10px] font-bold tracking-wide text-[#315b30] uppercase">
                Review
            </h3>
            <dl className="mt-2 space-y-1.5 text-xs">
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-gray-500">
                        Type
                    </dt>
                    <dd className="text-gray-800">
                        {isEvent ? 'Event' : 'Informational Update'}
                    </dd>
                </div>
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-gray-500">
                        Audience
                    </dt>
                    <dd className="text-gray-800">
                        {formatAudienceLabel(audience)}
                    </dd>
                </div>
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-gray-500">
                        Attendance
                    </dt>
                    <dd className="text-gray-800">{attendanceLabel}</dd>
                </div>
                <div className="flex gap-2">
                    <dt className="w-24 shrink-0 font-semibold text-gray-500">
                        Publication
                    </dt>
                    <dd className="text-gray-800">{approvalLabel}</dd>
                </div>
            </dl>
        </div>
    );
}
