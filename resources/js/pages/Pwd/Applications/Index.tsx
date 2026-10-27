import { Head } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import SectorApplicationList from '@/components/sectors/SectorApplicationList';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string; staff_id?: string; department?: string } };
    applications: SectorApplicationItem[];
}

export default function PwdApplicationsIndex({ auth, applications = [] }: Props) {
    return (
        <PwdLayout active="applications" user={auth?.user}>
            <Head title="PWD Sector Applications Queue" />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationList
                    sector="pwd"
                    applications={applications}
                    newApplicationUrl="/pwd/applications/create"
                    detailsBaseUrl="/pwd/applications"
                />
            </div>
        </PwdLayout>
    );
}
