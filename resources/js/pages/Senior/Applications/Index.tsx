import { Deferred, Head } from '@inertiajs/react';
import SeniorLayout from '@/layouts/SeniorLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import SectorApplicationList from '@/components/sectors/SectorApplicationList';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    applications: SectorApplicationItem[];
}

export default function SeniorApplicationsIndex({ auth, applications = [] }: Props) {
    return (
        <SeniorLayout active="applications" user={auth?.user}>
            <Head title="Senior Citizen Applications Queue" />
            <div className="mx-auto max-w-7xl">
                <Deferred
                    data={['applications']}
                    fallback={<PortalPageSkeleton rows={8} columns={5} />}
                >
                    <SectorApplicationList
                        sector="senior"
                        applications={applications}
                        newApplicationUrl="/senior/applications/create"
                        detailsBaseUrl="/senior/applications"
                    />
                </Deferred>
            </div>
        </SeniorLayout>
    );
}
