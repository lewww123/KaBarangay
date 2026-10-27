import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import SectorApplicationAdminReview from '@/components/sectors/SectorApplicationAdminReview';
import type { SectorApplicationItem } from '@/components/sectors/types';

interface Props {
    application: SectorApplicationItem;
}

export default function AdminSectorApplicationShow({ application }: Props) {
    return (
        <AdminLayout active="sector-applications">
            <Head title={`Admin Review - Application ${application.tracking_number}`} />
            <div className="mx-auto max-w-7xl">
                <SectorApplicationAdminReview
                    application={application}
                    backUrl="/admin/sector-applications"
                />
            </div>
        </AdminLayout>
    );
}
