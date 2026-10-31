import { Deferred, Head } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import SectorApplicationList from '@/components/sectors/SectorApplicationList';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    applications: SectorApplicationItem[];
}

export default function PwdApplicationsIndex({ auth, applications = [] }: Props) {
    return (
        <PwdLayout active="applications" user={auth?.user}>
            <Head title="PWD Sector Applications Queue" />
            <div className="mx-auto max-w-7xl">
                <Deferred
                    data={['applications']}
                    fallback={<PortalPageSkeleton rows={8} columns={5} />}
                >
                    <SectorApplicationList
                        sector="pwd"
                        applications={applications}
                        newApplicationUrl="/pwd/applications/create"
                        detailsBaseUrl="/pwd/applications"
                    />
                </Deferred>
            </div>
        </PwdLayout>
    );
}
