import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import SeniorLayout from '@/layouts/SeniorLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    user?: {
        name?: string;
        staff_id?: string;
    };
}

export default function SeniorAnnouncementShow({ announcement, user }: Props) {
    const attendanceHref =
        announcement.approval_status === 'approved' &&
        announcement.requires_attendance
            ? `/senior/announcements/${announcement.id}/attendance`
            : null;

    return (
        <SeniorLayout active="announcements" user={user}>
            <Head title={announcement.title} />
            <AnnouncementDetails
                announcement={announcement}
                backHref="/senior/announcements"
                attendanceHref={attendanceHref}
                showApprovalStatus
            />
        </SeniorLayout>
    );
}
