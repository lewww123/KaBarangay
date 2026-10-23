import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import PwdLayout from '@/layouts/PwdLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    user?: {
        name?: string;
        staff_id?: string;
    };
}

export default function PwdAnnouncementShow({ announcement, user }: Props) {
    const attendanceHref =
        announcement.approval_status === 'approved' &&
        announcement.requires_attendance
            ? `/pwd/announcements/${announcement.id}/attendance`
            : null;

    return (
        <PwdLayout active="announcements" user={user}>
            <Head title={announcement.title} />
            <AnnouncementDetails
                announcement={announcement}
                backHref="/pwd/announcements"
                attendanceHref={attendanceHref}
                showApprovalStatus
            />
        </PwdLayout>
    );
}
