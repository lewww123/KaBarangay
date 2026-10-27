import { Head } from '@inertiajs/react';
import AnnouncementDetails from '@/components/announcements/AnnouncementDetails';
import type { AnnouncementDetailsRecord } from '@/components/announcements/AnnouncementDetails';
import ResidentLayout from '@/layouts/ResidentLayout';

interface Props {
    announcement: AnnouncementDetailsRecord;
    kind?: 'announcements' | 'updates';
    period?: 'current' | 'past';
}

export default function ResidentAnnouncementDetails({
    announcement,
    kind = 'announcements',
    period = 'current',
}: Props) {
    const isUpdates = kind === 'updates';

    return (
        <ResidentLayout active={isUpdates ? 'updates' : 'announcements'}>
            <Head title={announcement.title} />

            <div className="mx-auto max-w-4xl">
                <AnnouncementDetails
                    announcement={announcement}
                    backHref={isUpdates ? `/resident/updates?period=${period}` : '/resident/announcements'}
                />
            </div>
        </ResidentLayout>
    );
}
