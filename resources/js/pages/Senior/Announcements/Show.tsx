import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import MarkAnnouncementDoneButton from '@/components/announcements/MarkAnnouncementDoneButton';
import {
    attendanceUrl,
    canMarkAnnouncementDone,
    isAttendanceAvailable,
} from '@/components/announcements/announcement';
import SeniorLayout from '@/layouts/SeniorLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    kind?: 'auto' | 'update' | 'program';
    user?: {
        name?: string;
        staff_id?: string;
    };
}

export default function SeniorAnnouncementShow({ announcement, user, kind = 'auto' }: Props) {
    const activeTab = kind === 'update' ? 'updates' : kind === 'program' ? 'programs' : 'announcements';
    const backHref = kind === 'update' ? '/senior/updates' : kind === 'program' ? '/senior/programs' : '/senior/announcements';
    const attendanceHref = isAttendanceAvailable(announcement)
        ? attendanceUrl('senior', announcement.id)
        : null;

    return (
        <SeniorLayout active={activeTab} user={user}>
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
                            portal="senior"
                            announcementId={announcement.id}
                            title={announcement.title}
                        />
                    </div>
                )}
            </div>
        </SeniorLayout>
    );
}
