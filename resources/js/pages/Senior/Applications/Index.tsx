import { Head } from '@inertiajs/react';
import SeniorLayout from '@/layouts/SeniorLayout';
import SectorApplicationList from '@/components/sectors/SectorApplicationList';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string; staff_id?: string; department?: string } };
    applications: SectorApplicationItem[];
}

export default function SeniorApplicationsIndex({ auth, applications = [] }: Props) {
    return (
        <SeniorLayout active="applications" user={auth?.user}>
            <Head title="Senior Citizen Applications Queue" />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationList
                    sector="senior"
                    applications={applications}
                    newApplicationUrl="/senior/applications/create"
                    detailsBaseUrl="/senior/applications"
                />
            </div>
        </SeniorLayout>
    );
}
