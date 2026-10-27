import { Head } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import SectorApplicationStaffReview from '@/components/sectors/SectorApplicationStaffReview';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string; staff_id?: string; department?: string } };
    application: SectorApplicationItem;
}

export default function PwdApplicationShow({ auth, application }: Props) {
    return (
        <PwdLayout active="applications" user={auth?.user}>
            <Head title={`Review PWD Application - ${application.tracking_number}`} />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationStaffReview
                    application={application}
                    backUrl="/pwd/applications"
                />
            </div>
        </PwdLayout>
    );
}
