import { Head } from '@inertiajs/react';
import SeniorLayout from '@/layouts/SeniorLayout';
import SectorApplicationWizard from '@/components/sectors/SectorApplicationWizard';

interface Props {
    auth?: { user?: { name?: string; staff_id?: string; department?: string } };
}

export default function SeniorApplicationCreate({ auth }: Props) {
    return (
        <SeniorLayout active="applications" user={auth?.user}>
            <Head title="New Senior Citizen Application" />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationWizard
                    sector="senior"
                    cancelUrl="/senior/applications"
                />
            </div>
        </SeniorLayout>
    );
}
