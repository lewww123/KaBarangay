import { Head } from '@inertiajs/react';
import SeniorLayout from '@/layouts/SeniorLayout';
import ResidentDirectoryTable from '@/components/sectors/ResidentDirectoryTable';
import type { ResidentDirectoryItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string; staff_id?: string; department?: string } };
    residents: ResidentDirectoryItem[];
    puroks?: { id: number; name: string }[];
}

export default function SeniorResidentsIndex({ auth, residents = [], puroks = [] }: Props) {
    return (
        <SeniorLayout active="residents" user={auth?.user}>
            <Head title="Senior Citizens Members" />
            <div className="mx-auto max-w-7xl">
                <ResidentDirectoryTable
                    sector="senior"
                    residents={residents}
                    puroks={puroks}
                    createApplicationUrl="/senior/applications/create"
                />
            </div>
        </SeniorLayout>
    );
}
