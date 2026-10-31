import { Head } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';
import SectorApplicationStaffReview from '@/components/sectors/SectorApplicationStaffReview';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    application: SectorApplicationItem;
}

export default withDeferredPage(PwdApplicationShow, ['application'], (props) => (
    <PwdLayout active="applications" user={props.auth?.user}><PortalPageSkeleton list /></PwdLayout>
));

function PwdApplicationShow({ auth, application }: Props) {
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
