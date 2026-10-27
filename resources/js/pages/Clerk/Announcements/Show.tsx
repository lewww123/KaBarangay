import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import MarkAnnouncementDoneButton from '@/components/announcements/MarkAnnouncementDoneButton';
import {
    attendanceUrl,
    canMarkAnnouncementDone,
    isAttendanceAvailable,
} from '@/components/announcements/announcement';
import ClerkLayout from '@/layouts/ClerkLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    kind?: 'auto' | 'update' | 'program';
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
}

export default function ClerkAnnouncementShow({ announcement, user, kind = 'auto' }: Props) {
    const activeTab = kind === 'update' ? 'updates' : kind === 'program' ? 'programs' : 'announcements';
    const backHref = kind === 'update' ? '/clerk/updates' : kind === 'program' ? '/clerk/programs' : '/clerk/announcements';
    const attendanceHref = isAttendanceAvailable(announcement)
        ? attendanceUrl('clerk', announcement.id)
        : null;

    return (
        <ClerkLayout active={activeTab} user={user}>
            <Head title={announcement.title} />
            <div className="space-y-5">
                <AnnouncementDetails
                    announcement={announcement}
                    backHref={backHref}
                    attendanceHref={attendanceHref}
                    showApprovalStatus
                    showAttendanceSetting
                    showImportance
                    showCompletion
                />

                {canMarkAnnouncementDone(announcement) && (
                    <div className="flex justify-end">
                        <MarkAnnouncementDoneButton
                            portal="clerk"
                            announcementId={announcement.id}
                            title={announcement.title}
                        />
                    </div>
                )}
            </div>
        </ClerkLayout>
    );
}
