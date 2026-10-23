import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import ClerkLayout from '@/layouts/ClerkLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
}

export default function ClerkAnnouncementShow({ announcement, user }: Props) {
    const attendanceHref =
        announcement.approval_status === 'approved' &&
        announcement.requires_attendance
            ? `/clerk/announcements/${announcement.id}/attendance`
            : null;

    return (
        <ClerkLayout active="announcements" user={user}>
            <Head title={announcement.title} />
            <AnnouncementDetails
                announcement={announcement}
                backHref="/clerk/announcements"
                attendanceHref={attendanceHref}
                showApprovalStatus
            />
        </ClerkLayout>
    );
}
