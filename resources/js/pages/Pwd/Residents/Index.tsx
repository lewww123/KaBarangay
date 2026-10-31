import { Deferred, Head } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import ResidentDirectoryTable from '@/components/sectors/ResidentDirectoryTable';
import type { ResidentDirectoryItem } from '@/components/sectors/types';

interface Props {
    auth?: { user?: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    residents: ResidentDirectoryItem[];
    puroks?: { id: number; name: string }[];
}

export default function PwdResidentsIndex({ auth, residents = [], puroks = [] }: Props) {
    return (
        <PwdLayout active="residents" user={auth?.user}>
            <Head title="PWD Sector Members" />
            <div className="mx-auto max-w-7xl">
                <Deferred
                    data={['residents', 'puroks']}
                    fallback={<PortalPageSkeleton rows={8} columns={6} />}
                >
                    <ResidentDirectoryTable
                        sector="pwd"
                        residents={residents}
                        puroks={puroks}
                        createApplicationUrl="/pwd/applications/create"
                    />
                </Deferred>
            </div>
        </PwdLayout>
    );
}
