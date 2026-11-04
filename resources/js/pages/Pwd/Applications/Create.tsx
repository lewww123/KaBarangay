import { Head } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';
import SectorApplicationWizard from '@/components/sectors/SectorApplicationWizard';

interface Props {
    auth?: {
        user?: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
}

export default function PwdApplicationCreate({ auth }: Props) {
    return (
        <PwdLayout active="applications" user={auth?.user}>
            <Head title="New PWD Sector Application" />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationWizard
                    sector="pwd"
                    cancelUrl="/pwd/applications"
                />
            </div>
        </PwdLayout>
    );
}
