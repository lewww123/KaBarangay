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
                    <div className="flex items-center gap-2 text-xs font-semibold tracking-wider text-ink-muted uppercase">
                        <Link href={backUrl} className="hover:text-ink-strong">
                            Admin / Sector Applications
                        </Link>
                        <span>/</span>
                        <span className="font-semibold text-ink-strong">
                            {application.tracking_number}
                        </span>
                    </div>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink-strong">
                        Sector Application Oversight
                    </h1>
                </div>

                <Link
                    href={backUrl}
                    className="inline-flex items-center text-xs font-semibold text-ink-muted hover:text-ink-strong"
                >
                    Back to All Applications
                </Link>
            </div>

            <div className="rounded-xl border border-blue-200 bg-blue-50/70 p-4 text-xs text-blue-900 shadow-card">
                <span className="font-semibold">Read-only oversight:</span> Sector
                staff review and decide PWD and Senior Citizens applications.
                Administrators monitor the recorded decision and audit trail
                here.
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-6 shadow-card">
                        <div className="flex items-center justify-between border-b border-line-soft pb-3">
                            <h2 className="text-base font-semibold text-ink-strong">
                                Applicant Resident Profile
                            </h2>
                            <span className="rounded-lg bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 uppercase">
                                {application.status.replace(/_/g, ' ')}
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-4 text-xs">
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Full Name
                                </span>
                                <p className="mt-0.5 text-sm font-semibold text-ink-strong">
                                    {application.applicant_name}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Resident ID
                                </span>
                                <p className="mt-0.5 font-mono text-sm font-semibold text-ink-strong">
                                    {application.resident_number}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Barangay & Purok
                                </span>
                                <p className="mt-0.5 font-semibold text-ink-strong">
                                    Barangay San Jose ({application.purok})
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Target Sector
                                </span>
                                <p className="mt-0.5 font-semibold text-amber-900 uppercase">
                                    {application.sector_name}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Submission Origin
                                </span>
                                <p className="mt-0.5 font-semibold text-ink-strong">
                                    {application.submission_type ===
                                    'staff_assisted'
                                        ? `Staff-Assisted by ${application.submitted_by_name || 'Desk Staff'}`
                                        : 'Resident Portal Self-Service'}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Submitted On
                                </span>
                                <p className="mt-0.5 font-semibold text-ink-strong">
                                    {application.submitted_at}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-6 shadow-card">
                        <h2 className="border-b border-line-soft pb-3 text-base font-semibold text-ink-strong">
                            Sector Staff Review
                        </h2>

                        <div className="space-y-2 rounded-xl border border-line-soft bg-canvas/70 p-4 text-xs">
                            <div className="flex items-center justify-between text-ink-muted">
                                <span>
                                    Reviewed by:{' '}
                                    <strong className="text-ink-strong">
                                        {application.reviewed_by_staff_name ||
                                            'Sector Staff'}
                                    </strong>
                                </span>
                                {application.staff_checklist_completed && (
                                    <span className="text-[11px] font-semibold text-emerald-700">
                                        Checklist completed
                                    </span>
                                )}
                            </div>
                            <p className="text-ink-strong italic">
                                "
                                {application.staff_review_notes ||
                                    'No staff review remarks recorded.'}
                                "
                            </p>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-6 shadow-card">
                        <h2 className="border-b border-line-soft pb-3 text-base font-semibold text-ink-strong">
                            Supporting Evidentiary Documents
                        </h2>

                        <div className="space-y-3">
                            {application.documents.length === 0 ? (
                                <p className="py-2 text-xs text-ink-muted">
                                    No documents attached.
                                </p>
                            ) : (
                                application.documents.map((doc) => (
                                    <div
                                        key={doc.id}
                                        className="flex items-center justify-between rounded-xl border border-line-soft bg-canvas/50 p-3.5 text-xs"
                                    >
                                        <div>
                                            <p className="font-semibold text-ink-strong">
                                                {doc.title}
                                            </p>
                                            <p className="text-[11px] text-ink-muted">
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
                                            className="inline-flex items-center rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-semibold text-ink shadow-card hover:bg-canvas"
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
                    <div className="space-y-3 rounded-xl border border-line-soft bg-white p-6 shadow-card">
                        <h3 className="text-xs font-semibold tracking-wider text-ink-muted uppercase">
                            Recorded Workflow
                        </h3>
                        <div className="space-y-3 border-l-2 border-line-soft pl-3 text-xs text-ink-muted">
                            <div>
                                <span className="font-semibold text-ink-strong">
                                    Submitted
                                </span>
                                <p className="text-[11px] text-ink-faint">
                                    {application.submitted_at} - Initial intake
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold text-ink-strong">
                                    Sector Staff Review
                                </span>
                                <p className="text-[11px] text-ink-faint">
                                    {application.reviewed_by_staff_name
                                        ? `Recorded by ${application.reviewed_by_staff_name}`
                                        : 'Awaiting sector staff decision'}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold text-ink-strong">
                                    Current Status
                                </span>
                                <p className="text-[11px] text-ink-faint">
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
