import { Head, Link } from '@inertiajs/react';
import ResidentLayout from '@/layouts/ResidentLayout';

/*
|--------------------------------------------------------------------------
| Citizen's Charter
|--------------------------------------------------------------------------
|
| No authoritative Citizen's Charter content currently exists in this
| repository. This page provides the required structure only. Every field
| that needs official barangay input is explicitly marked as a gap so that
| no processing time, fee, requirement, office detail, or legal statement
| is invented.
|
*/

interface ServiceBlock {
    title: string;
    summary: string;
    href?: string;
    actionLabel?: string;
}

const services: ServiceBlock[] = [
    {
        title: 'Barangay Certificates',
        summary:
            'Request and claim barangay certificates and clearances through the Resident Portal.',
        href: '/resident/certificates',
        actionLabel: 'Go to Request Certificate',
    },
    {
        title: 'Complaints (Lupong Tagapamayapa)',
        summary:
            'File a complaint or dispute and monitor its progress through the Resident Portal.',
        href: '/resident/lupon',
        actionLabel: 'Go to Lupon',
    },
    {
        title: 'Barangay Announcements',
        summary:
            'View official barangay news, advisories, and events published to residents.',
        href: '/resident/announcements',
        actionLabel: 'View Announcements',
    },
];

const pendingFields = [
    'Who may avail',
    'Documentary requirements',
    'Processing time',
    'Fees',
    'Office or person responsible',
    'Legal basis or reference',
];

export default function CitizenCharter() {
    return (
        <ResidentLayout active="charter">
            <Head title="Citizen's Charter" />

            <div className="mx-auto w-full max-w-4xl">
                <div className="mb-5 sm:mb-6">
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        Citizen&apos;s Charter
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        Barangay services and the steps to avail them.
                    </p>
                </div>

                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm sm:p-6">
                    <h2 className="text-sm font-bold text-amber-900">
                        Official content pending
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-amber-800">
                        The official Citizen&apos;s Charter details — including
                        processing times, fees, documentary requirements, office
                        information, and legal references — have not been provided
                        for this portal. The sections below show the required page
                        structure. These details must be supplied and approved by
                        the barangay before this page is published as authoritative.
                    </p>
                </div>

                <div className="mt-4 space-y-4">
                    {services.map((service) => (
                        <div
                            key={service.title}
                            className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
                        >
                            <h2 className="text-base font-bold text-gray-900">
                                {service.title}
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                {service.summary}
                            </p>

                            <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                {pendingFields.map((field) => (
                                    <div
                                        key={field}
                                        className="rounded-xl border border-dashed border-gray-300 bg-gray-50/60 p-3"
                                    >
                                        <dt className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                            {field}
                                        </dt>
                                        <dd className="mt-1 text-xs text-gray-500">
                                            To be provided by the barangay
                                        </dd>
                                    </div>
                                ))}
                            </dl>

                            {service.href && (
                                <Link
                                    href={service.href}
                                    className="mt-4 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                >
                                    {service.actionLabel}
                                </Link>
                            )}
                        </div>
                    ))}
                </div>

                <div className="mt-4 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">
                    <p className="text-xs leading-5 text-gray-500">
                        Certificate fees configured in this portal are shown on the
                        Request Certificate page. Those fees are data entered by the
                        barangay and are not restated here.
                    </p>
                </div>
            </div>
        </ResidentLayout>
    );
}
