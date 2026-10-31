import { Head } from '@inertiajs/react';
import SeniorLayout from '@/layouts/SeniorLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';
import SectorApplicationStaffReview from '@/components/sectors/SectorApplicationStaffReview';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    application: SectorApplicationItem;
}

export default withDeferredPage(SeniorApplicationShow, ['application'], (props) => (
    <SeniorLayout active="applications" user={props.auth?.user}><PortalPageSkeleton list /></SeniorLayout>
));

function SeniorApplicationShow({ auth, application }: Props) {
    return (
        <SeniorLayout active="applications" user={auth?.user}>
            <Head title={`Review Senior Application - ${application.tracking_number}`} />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationStaffReview
                    application={application}
                    backUrl="/senior/applications"
                />
            </div>
        </SeniorLayout>
    );
}
