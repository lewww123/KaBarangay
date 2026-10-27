import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import MarkAnnouncementDoneButton from '@/components/announcements/MarkAnnouncementDoneButton';
import {
    attendanceUrl,
    canMarkAnnouncementDone,
    isAttendanceAvailable,
} from '@/components/announcements/announcement';
import PwdLayout from '@/layouts/PwdLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    kind?: 'auto' | 'update' | 'program';
    user?: {
        name?: string;
        staff_id?: string;
    };
}

export default function PwdAnnouncementShow({ announcement, user, kind = 'auto' }: Props) {
    const activeTab = kind === 'update' ? 'updates' : kind === 'program' ? 'programs' : 'announcements';
    const backHref = kind === 'update' ? '/pwd/updates' : kind === 'program' ? '/pwd/programs' : '/pwd/announcements';
    const attendanceHref = isAttendanceAvailable(announcement)
        ? attendanceUrl('pwd', announcement.id)
        : null;

    return (
        <PwdLayout active={activeTab} user={user}>
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
                            portal="pwd"
                            announcementId={announcement.id}
                            title={announcement.title}
                        />
                    </div>
                )}
            </div>
        </PwdLayout>
    );
}
