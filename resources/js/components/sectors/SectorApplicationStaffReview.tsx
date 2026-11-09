import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { SectorApplicationItem } from './types';

interface Props {
    application: SectorApplicationItem;
    backUrl: string;
}

export default function SectorApplicationStaffReview({
    application,
    backUrl,
}: Props) {
    const [checklist, setChecklist] = useState({
        identityVerified: true,
        residencyConfirmed: true,
        documentsComplete: true,
        eligibilityMet: false,
    });

    const [staffNotes, setStaffNotes] = useState(
        application.staff_review_notes || '',
    );
    const [rejectionReason, setRejectionReason] = useState('');
    const [actionFeedback, setActionFeedback] = useState<string | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);

    const allChecked =
        checklist.identityVerified &&
        checklist.residencyConfirmed &&
        checklist.documentsComplete &&
        checklist.eligibilityMet;

    const sectorLabel =
        application.sector === 'senior' ? 'Senior Citizens' : 'PWD Sector';

    const handleApprove = () => {
        setIsProcessing(true);
        router.post(
            application.sector === 'senior'
                ? `/senior/applications/${application.id}/approve`
                : `/pwd/applications/${application.id}/approve`,
            {
                checklist,
                review_notes: staffNotes,
            },
            {
                onSuccess: () => {
                    setIsProcessing(false);
                    setActionFeedback(
                        'Application approved and sector membership activated.',
                    );
                },
                onError: (errors) => {
                    setIsProcessing(false);
                    alert(Object.values(errors).flat().join('\n'));
                },
            },
        );
    };

    const handleReject = () => {
        if (!rejectionReason.trim()) {
            alert('Please provide a reason for rejecting this application.');
            return;
        }

        setIsProcessing(true);
        router.post(
            application.sector === 'senior'
                ? `/senior/applications/${application.id}/reject`
                : `/pwd/applications/${application.id}/reject`,
            {
                reason: rejectionReason,
            },
            {
                onSuccess: () => {
                    setIsProcessing(false);
                    setActionFeedback(
                        'Application rejected and the reason recorded.',
                    );
                },
                onError: (errors) => {
                    setIsProcessing(false);
                    alert(Object.values(errors).flat().join('\n'));
                },
            },
        );
    };

    const handleRequestCorrection = () => {
        if (!staffNotes.trim()) {
            alert(
                'Please specify the correction details in the Staff Recommendation Notes field.',
            );
            return;
        }

        setIsProcessing(true);
        router.post(
            application.sector === 'senior'
                ? `/senior/applications/${application.id}/correction`
                : `/pwd/applications/${application.id}/correction`,
            {
                correction_notes: staffNotes,
            },
            {
                onSuccess: () => {
                    setIsProcessing(false);
                    setActionFeedback(
                        'Flagged for correction and returned to applicant notes.',
                    );
                },
                onError: (errors) => {
                    setIsProcessing(false);
                    alert(Object.values(errors).flat().join('\n'));
                },
            },
        );
    };

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            {/* TOP BAR / BREADCRUMB */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2 text-xs font-semibold tracking-wider text-ink-muted uppercase">
                        <Link href={backUrl} className="hover:text-ink-strong">
                            {sectorLabel} Applications
                        </Link>
                        <span>/</span>
                        <span className="font-semibold text-ink-strong">
                            {application.tracking_number}
                        </span>
                    </div>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink-strong">
                        Staff Completeness Review
                    </h1>
                </div>

                <Link
                    href={backUrl}
                    className="inline-flex items-center text-xs font-semibold text-ink-muted hover:text-ink-strong"
                >
                    Back to Queue
                </Link>
            </div>

            <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs text-amber-900 shadow-card">
                <span className="text-base">!</span>
                <div>
                    <span className="font-semibold">
                        Sector Staff Decision Stage:
                    </span>{' '}
                    Verify document completeness, residency, and sector
                    eligibility. Approving this application activates the
                    resident's sector membership. Rejections require a recorded
                    reason.
                </div>
            </div>

            {actionFeedback && (
                <div className="rounded-xl border border-brand-muted bg-brand-soft p-4 text-xs font-semibold text-brand-deep">
                    {actionFeedback}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* APPLICANT & DETAILS CARD */}
                <div className="space-y-6 lg:col-span-2">
                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-5 shadow-card">
                        <div className="flex items-center justify-between border-b border-line-soft pb-3">
                            <h2 className="text-base font-semibold text-ink-strong">
                                Applicant Information
                            </h2>
                            <span className="rounded bg-secondary px-2 py-0.5 font-mono text-xs font-semibold text-ink-muted">
                                {application.tracking_number}
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
                                    Resident Number
                                </span>
                                <p className="mt-0.5 font-mono text-sm font-semibold text-ink-strong">
                                    {application.resident_number}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Purok
                                </span>
                                <p className="mt-0.5 font-semibold text-ink-strong">
                                    {application.purok}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Submission Mode
                                </span>
                                <p className="mt-0.5 font-semibold text-ink-strong">
                                    {application.submission_type ===
                                    'staff_assisted'
                                        ? `Staff-Assisted (${application.submitted_by_name || 'Desk Staff'})`
                                        : 'Resident Portal Self-Service'}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Date Submitted
                                </span>
                                <p className="mt-0.5 font-semibold text-ink-strong">
                                    {application.submitted_at}
                                </p>
                            </div>
                            <div>
                                <span className="font-semibold tracking-wider text-ink-faint uppercase">
                                    Target Sector
                                </span>
                                <p className="mt-0.5 font-semibold text-amber-800 uppercase">
                                    {application.sector_name}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* ATTACHED DOCUMENTS */}
                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-5 shadow-card">
                        <h2 className="border-b border-line-soft pb-3 text-base font-semibold text-ink-strong">
                            Submitted Proofs & Documents
                        </h2>

                        <div className="space-y-3">
                            {application.documents &&
                            application.documents.length > 0 ? (
                                application.documents.map((doc) => (
                                    <div
                                        key={doc.id}
                                        className="flex items-center justify-between rounded-xl border border-line-soft bg-canvas/60 p-3 text-xs"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="text-lg"></span>
                                            <div>
                                                <p className="font-semibold text-ink-strong">
                                                    {doc.title}
                                                </p>
                                                <p className="text-[11px] text-ink-muted">
                                                    {doc.file_name ||
                                                        'Scanned Document Copy'}{' '}
                                                    •{' '}
                                                    {doc.file_size || '350 KB'}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <a
                                                href={`/sector-applications/${application.id}/documents/${doc.id}/file`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center rounded-lg border border-line bg-white px-2.5 py-1 text-xs font-semibold text-ink shadow-card hover:bg-canvas"
                                            >
                                                Inspect
                                            </a>
                                            <span className="rounded-lg bg-brand-soft px-2 py-0.5 text-[10px] font-semibold text-brand-deep uppercase">
                                                Attached
                                            </span>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <p className="py-2 text-xs text-ink-muted">
                                    No documents attached.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* REVIEW CHECKLIST & ACTIONS */}
                <div className="space-y-6">
                    <div className="space-y-4 rounded-xl border border-line-soft bg-white p-5 shadow-card">
                        <h2 className="border-b border-line-soft pb-3 text-base font-semibold text-ink-strong">
                            Completeness Checklist
                        </h2>

                        <div className="space-y-3 text-xs">
                            <label className="flex cursor-pointer items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={checklist.identityVerified}
                                    onChange={(e) =>
                                        setChecklist((p) => ({
                                            ...p,
                                            identityVerified: e.target.checked,
                                        }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                />
                                <span>
                                    Identity matches active resident register
                                    record
                                </span>
                            </label>

                            <label className="flex cursor-pointer items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={checklist.residencyConfirmed}
                                    onChange={(e) =>
                                        setChecklist((p) => ({
                                            ...p,
                                            residencyConfirmed:
                                                e.target.checked,
                                        }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                />
                                <span>
                                    Verified residency in Barangay San Jose
                                </span>
                            </label>

                            <label className="flex cursor-pointer items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={checklist.documentsComplete}
                                    onChange={(e) =>
                                        setChecklist((p) => ({
                                            ...p,
                                            documentsComplete: e.target.checked,
                                        }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                />
                                <span>
                                    Proof of eligibility is legible and complete
                                </span>
                            </label>

                            <label className="flex cursor-pointer items-start gap-2.5">
                                <input
                                    type="checkbox"
                                    checked={checklist.eligibilityMet}
                                    onChange={(e) =>
                                        setChecklist((p) => ({
                                            ...p,
                                            eligibilityMet: e.target.checked,
                                        }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                />
                                <span className="font-semibold text-ink-strong">
                                    I verify this applicant meets {sectorLabel}{' '}
                                    sector eligibility requirements
                                </span>
                            </label>
                        </div>

                        {/* STAFF NOTES */}
                        <div className="pt-2">
                            <label className="mb-1 block text-[11px] font-semibold tracking-wider text-ink-muted uppercase">
                                Staff Review Remarks
                            </label>
                            <textarea
                                rows={3}
                                value={staffNotes}
                                onChange={(e) => setStaffNotes(e.target.value)}
                                placeholder="Add internal review notes..."
                                className="w-full rounded-xl border border-line bg-canvas/50 p-2.5 text-xs text-ink-strong focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                            />
                        </div>

                        <div className="pt-2">
                            <label className="mb-1 block text-[11px] font-semibold tracking-wider text-ink-muted uppercase">
                                Rejection Reason
                            </label>
                            <textarea
                                rows={2}
                                value={rejectionReason}
                                onChange={(e) =>
                                    setRejectionReason(e.target.value)
                                }
                                placeholder="Required when rejecting the application..."
                                className="w-full rounded-xl border border-line bg-canvas/50 p-2.5 text-xs text-ink-strong focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500 focus:outline-none"
                            />
                        </div>

                        <div className="space-y-2 border-t border-line-soft pt-3">
                            <button
                                type="button"
                                onClick={handleApprove}
                                disabled={!allChecked || isProcessing}
                                className="w-full rounded-xl bg-brand py-2.5 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep disabled:opacity-40"
                            >
                                Approve & Activate Membership
                            </button>

                            <button
                                type="button"
                                onClick={handleRequestCorrection}
                                disabled={isProcessing}
                                className="w-full rounded-xl border border-line bg-white py-2.5 text-xs font-semibold text-ink transition hover:bg-canvas disabled:opacity-40"
                            >
                                Request Correction from Applicant
                            </button>

                            <button
                                type="button"
                                onClick={handleReject}
                                disabled={isProcessing}
                                className="w-full rounded-xl border border-red-200 bg-red-50 py-2.5 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-40"
                            >
                                Reject Application
                            </button>
                        </div>

                        <p className="text-center text-[10px] text-ink-faint">
                            All decisions are recorded in the audit trail.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
