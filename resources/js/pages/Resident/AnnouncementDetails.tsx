import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import ResidentLayout from '@/layouts/ResidentLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
}

export default function ResidentAnnouncementDetails({ announcement }: Props) {
    return (
        <ResidentLayout active="announcements">
            <Head title={announcement.title} />

            <div className="mx-auto max-w-4xl">
                <AnnouncementDetails
                    announcement={announcement}
                    backHref="/resident/announcements"
                />
            </div>
        </ResidentLayout>
    );
}
