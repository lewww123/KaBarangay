import { Link } from '@inertiajs/react';
import type { SectorApplicationItem } from './types';

interface Props {
    application: SectorApplicationItem;
    backUrl: string;
}

export default function SectorApplicationAdminReview({
    application,
    backUrl,
}: Props) {
    return (
        <div className="mx-auto max-w-4xl space-y-6">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2 text-xs font-semibold tracking-wider text-gray-500 uppercase">
                        <Link href={backUrl} className="hover:text-gray-800">
                            Admin / Sector Applications
                        </Link>
                        <span>/</span>
                        <span className="font-bold text-gray-800">
                            {application.tracking_number}
                        </span>
                    </div>
                    <h1 className="mt-1 text-2xl font-black tracking-tight text-gray-900">
                        Sector Application Oversight
                    </h1>
                </div>

                <Link
                    href={backUrl}
                    className="inline-flex items-center text-xs font-bold text-gray-500 hover:text-gray-800"
                >
                    Back to All Applications
                </Link>
            </div>

            <div className="rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-xs text-blue-900 shadow-sm">
                <span className="font-bold">Read-only oversight:</span> Sector
                staff review and decide PWD and Senior Citizens applications.
                Administrators monitor the recorded decision and audit trail
                here.
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-3">
                            <h2 className="text-base font-bold text-gray-900">
                                Applicant Resident Profile
                            </h2>
                            <span className="rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 uppercase">
                                {application.status.replace(/_/g, ' ')}
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-4 text-xs">
                            <div>
                                <span className="font-bold tracking-wider text-gray-400 uppercase">
                                    Full Name
                                </span>
                                <p className="mt-0.5 text-sm font-extrabold text-gray-900">
                                    {application.applicant_name}
                                </p>
                            </div>
                            <div>
                                <span className="font-bold tracking-wider text-gray-400 uppercase">
                                    Resident ID
                                </span>
                                <p className="mt-0.5 font-mono text-sm font-bold text-gray-800">
                                    {application.resident_number}
                                </p>
                            </div>
                            <div>
                                <span className="font-bold tracking-wider text-gray-400 uppercase">
                                    Barangay & Purok
                                </span>
                                <p className="mt-0.5 font-semibold text-gray-800">
                                    Barangay San Jose ({application.purok})
                                </p>
                            </div>
                            <div>
                                <span className="font-bold tracking-wider text-gray-400 uppercase">
                                    Target Sector
                                </span>
                                <p className="mt-0.5 font-extrabold text-amber-900 uppercase">
                                    {application.sector_name}
                                </p>
                            </div>
                            <div>
                                <span className="font-bold tracking-wider text-gray-400 uppercase">
                                    Submission Origin
                                </span>
                                <p className="mt-0.5 font-semibold text-gray-800">
                                    {application.submission_type ===
                                    'staff_assisted'
                                        ? `Staff-Assisted by ${application.submitted_by_name || 'Desk Staff'}`
                                        : 'Resident Portal Self-Service'}
                                </p>
                            </div>
                            <div>
                                <span className="font-bold tracking-wider text-gray-400 uppercase">
                                    Submitted On
                                </span>
                                <p className="mt-0.5 font-semibold text-gray-800">
                                    {application.submitted_at}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 className="border-b border-gray-100 pb-3 text-base font-bold text-gray-900">
                            Sector Staff Review
                        </h2>

                        <div className="space-y-2 rounded-xl border border-gray-200 bg-gray-50/70 p-4 text-xs">
                            <div className="flex items-center justify-between text-gray-600">
                                <span>
                                    Reviewed by:{' '}
                                    <strong className="text-gray-800">
                                        {application.reviewed_by_staff_name ||
                                            'Sector Staff'}
                                    </strong>
                                </span>
                                {application.staff_checklist_completed && (
                                    <span className="text-[11px] font-bold text-emerald-700">
                                        Checklist completed
                                    </span>
                                )}
                            </div>
                            <p className="text-gray-800 italic">
                                "
                                {application.staff_review_notes ||
                                    'No staff review remarks recorded.'}
                                "
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h2 className="border-b border-gray-100 pb-3 text-base font-bold text-gray-900">
                            Supporting Evidentiary Documents
                        </h2>

                        <div className="space-y-3">
                            {application.documents.length === 0 ? (
                                <p className="py-2 text-xs text-gray-500">
                                    No documents attached.
                                </p>
                            ) : (
                                application.documents.map((doc) => (
                                    <div
                                        key={doc.id}
                                        className="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50/50 p-3.5 text-xs"
                                    >
                                        <div>
                                            <p className="font-bold text-gray-900">
                                                {doc.title}
                                            </p>
                                            <p className="text-[11px] text-gray-500">
                                                {doc.file_name ||
                                                    'Official Attached Certificate'}
                                                {doc.file_size
                                                    ? ` - ${doc.file_size}`
                                                    : ''}
                                            </p>
                                        </div>

                                        <a
                                            href={`/sector-applications/${application.id}/documents/${doc.id}/file`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                                        >
                                            Inspect Document
                                        </a>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="space-y-3 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 className="text-xs font-bold tracking-wider text-gray-500 uppercase">
                            Recorded Workflow
                        </h3>
                        <div className="space-y-3 border-l-2 border-gray-200 pl-3 text-xs text-gray-600">
                            <div>
                                <span className="font-bold text-gray-800">
                                    Submitted
                                </span>
                                <p className="text-[11px] text-gray-400">
                                    {application.submitted_at} - Initial intake
                                </p>
                            </div>
                            <div>
                                <span className="font-bold text-gray-800">
                                    Sector Staff Review
                                </span>
                                <p className="text-[11px] text-gray-400">
                                    {application.reviewed_by_staff_name
                                        ? `Recorded by ${application.reviewed_by_staff_name}`
                                        : 'Awaiting sector staff decision'}
                                </p>
                            </div>
                            <div>
                                <span className="font-bold text-gray-800">
                                    Current Status
                                </span>
                                <p className="text-[11px] text-gray-400">
                                    {application.status.replace(/_/g, ' ')}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
