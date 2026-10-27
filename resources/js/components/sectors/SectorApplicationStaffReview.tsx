import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { SectorApplicationItem } from './types';

interface Props {
    application: SectorApplicationItem;
    backUrl: string;
}

export default function SectorApplicationStaffReview({ application, backUrl }: Props) {
    const [checklist, setChecklist] = useState({
        identityVerified: true,
        residencyConfirmed: true,
        documentsComplete: true,
        eligibilityMet: false,
    });

    const [staffNotes, setStaffNotes] = useState(application.staff_review_notes || '');
    const [rejectionReason, setRejectionReason] = useState('');
    const [actionFeedback, setActionFeedback] = useState<string | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);

    const allChecked =
        checklist.identityVerified &&
        checklist.residencyConfirmed &&
        checklist.documentsComplete &&
        checklist.eligibilityMet;

    const sectorLabel = application.sector === 'senior' ? 'Senior Citizens' : 'PWD Sector';

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
                    setActionFeedback('Application approved and sector membership activated.');
                },
                onError: (errors) => {
                    setIsProcessing(false);
                    alert(Object.values(errors).flat().join('\n'));
                },
            }
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
                    setActionFeedback('Application rejected and the reason recorded.');
                },
                onError: (errors) => {
                    setIsProcessing(false);
                    alert(Object.values(errors).flat().join('\n'));
                },
            }
        );
    };

    const handleRequestCorrection = () => {
        if (!staffNotes.trim()) {
            alert('Please specify the correction details in the Staff Recommendation Notes field.');
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
                    setActionFeedback('Flagged for correction and returned to applicant notes.');
                },
                onError: (errors) => {
                    setIsProcessing(false);
                    alert(Object.values(errors).flat().join('\n'));
                },
            }
        );
    };

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            {/* TOP BAR / BREADCRUMB */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                        <Link href={backUrl} className="hover:text-gray-800">
                            {sectorLabel} Applications
                        </Link>
                        <span>/</span>
                        <span className="text-gray-800 font-bold">{application.tracking_number}</span>
                    </div>
                    <h1 className="mt-1 text-2xl font-black text-gray-900 tracking-tight">
                        Staff Completeness Review
                    </h1>
                </div>

                <Link
                    href={backUrl}
                    className="inline-flex items-center text-xs font-bold text-gray-500 hover:text-gray-800"
                >
                    ← Back to Queue
                </Link>
            </div>

            <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs text-amber-900 shadow-sm flex items-start gap-3">
                <span className="text-base">!</span>
                <div>
                    <span className="font-bold">Sector Staff Decision Stage:</span>{' '}
                    Verify document completeness, residency, and sector eligibility. Approving this application activates the resident's sector membership. Rejections require a recorded reason.
                </div>
            </div>

            {actionFeedback && (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-bold text-emerald-800">
                    ✓ {actionFeedback}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* APPLICANT & DETAILS CARD */}
                <div className="space-y-6 lg:col-span-2">
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-3">
                            <h2 className="text-base font-bold text-gray-900">Applicant Information</h2>
                            <span className="font-mono text-xs font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded">
                                {application.tracking_number}
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-4 text-xs">
                            <div>
                                <span className="text-gray-400 font-bold uppercase tracking-wider">Full Name</span>
                                <p className="mt-0.5 text-sm font-extrabold text-gray-900">{application.applicant_name}</p>
                            </div>
                            <div>
                                <span className="text-gray-400 font-bold uppercase tracking-wider">Resident Number</span>
                                <p className="mt-0.5 font-mono text-sm font-bold text-gray-800">{application.resident_number}</p>
                            </div>
                            <div>
                                <span className="text-gray-400 font-bold uppercase tracking-wider">Purok</span>
                                <p className="mt-0.5 font-semibold text-gray-800">{application.purok}</p>
                            </div>
                            <div>
                                <span className="text-gray-400 font-bold uppercase tracking-wider">Submission Mode</span>
                                <p className="mt-0.5 font-semibold text-gray-800">
                                    {application.submission_type === 'staff_assisted'
                                        ? `Staff-Assisted (${application.submitted_by_name || 'Desk Staff'})`
                                        : 'Resident Portal Self-Service'}
                                </p>
                            </div>
                            <div>
                                <span className="text-gray-400 font-bold uppercase tracking-wider">Date Submitted</span>
                                <p className="mt-0.5 font-semibold text-gray-800">{application.submitted_at}</p>
                            </div>
                            <div>
                                <span className="text-gray-400 font-bold uppercase tracking-wider">Target Sector</span>
                                <p className="mt-0.5 font-bold text-amber-800 uppercase">{application.sector_name}</p>
                            </div>
                        </div>
                    </div>

                    {/* ATTACHED DOCUMENTS */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                        <h2 className="text-base font-bold text-gray-900 border-b border-gray-100 pb-3">
                            Submitted Proofs & Documents
                        </h2>

                        <div className="space-y-3">
                            {application.documents && application.documents.length > 0 ? (
                                application.documents.map((doc) => (
                                    <div
                                        key={doc.id}
                                        className="flex items-center justify-between rounded-xl border border-gray-200 bg-gray-50/60 p-3 text-xs"
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="text-lg">📄</span>
                                            <div>
                                                <p className="font-bold text-gray-900">{doc.title}</p>
                                                <p className="text-[11px] text-gray-500">
                                                    {doc.file_name || 'Scanned Document Copy'} • {doc.file_size || '350 KB'}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <a
                                                href={`/sector-applications/${application.id}/documents/${doc.id}/file`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 shadow-sm inline-flex items-center"
                                            >
                                                Inspect
                                            </a>
                                            <span className="rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 uppercase">
                                                Attached
                                            </span>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <p className="text-xs text-gray-500 py-2">No documents attached.</p>
                            )}
                        </div>
                    </div>
                </div>

                {/* REVIEW CHECKLIST & ACTIONS */}
                <div className="space-y-6">
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                        <h2 className="text-base font-bold text-gray-900 border-b border-gray-100 pb-3">
                            Completeness Checklist
                        </h2>

                        <div className="space-y-3 text-xs">
                            <label className="flex items-start gap-2.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={checklist.identityVerified}
                                    onChange={(e) =>
                                        setChecklist((p) => ({ ...p, identityVerified: e.target.checked }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                />
                                <span>Identity matches active resident register record</span>
                            </label>

                            <label className="flex items-start gap-2.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={checklist.residencyConfirmed}
                                    onChange={(e) =>
                                        setChecklist((p) => ({ ...p, residencyConfirmed: e.target.checked }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                />
                                <span>Verified residency in Barangay San Jose</span>
                            </label>

                            <label className="flex items-start gap-2.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={checklist.documentsComplete}
                                    onChange={(e) =>
                                        setChecklist((p) => ({ ...p, documentsComplete: e.target.checked }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                />
                                <span>Proof of eligibility is legible and complete</span>
                            </label>

                            <label className="flex items-start gap-2.5 cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={checklist.eligibilityMet}
                                    onChange={(e) =>
                                        setChecklist((p) => ({ ...p, eligibilityMet: e.target.checked }))
                                    }
                                    className="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                />
                                <span className="font-bold text-gray-800">
                                    I verify this applicant meets {sectorLabel} sector eligibility requirements
                                </span>
                            </label>
                        </div>

                        {/* STAFF NOTES */}
                        <div className="pt-2">
                            <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                                Staff Review Remarks
                            </label>
                            <textarea
                                rows={3}
                                value={staffNotes}
                                onChange={(e) => setStaffNotes(e.target.value)}
                                placeholder="Add internal review notes..."
                                className="w-full rounded-xl border border-gray-300 bg-gray-50/50 p-2.5 text-xs text-gray-800 focus:border-[#3f6f3d] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#3f6f3d]"
                            />
                        </div>

                        <div className="pt-2">
                            <label className="block text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-1">
                                Rejection Reason
                            </label>
                            <textarea
                                rows={2}
                                value={rejectionReason}
                                onChange={(e) => setRejectionReason(e.target.value)}
                                placeholder="Required when rejecting the application..."
                                className="w-full rounded-xl border border-gray-300 bg-gray-50/50 p-2.5 text-xs text-gray-800 focus:border-red-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-red-500"
                            />
                        </div>

                        <div className="pt-3 border-t border-gray-100 space-y-2">
                            <button
                                type="button"
                                onClick={handleApprove}
                                disabled={!allChecked || isProcessing}
                                className="w-full rounded-xl bg-[#3f6f3d] py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-[#315b30] disabled:opacity-40"
                            >
                                Approve & Activate Membership
                            </button>

                            <button
                                type="button"
                                onClick={handleRequestCorrection}
                                disabled={isProcessing}
                                className="w-full rounded-xl border border-gray-300 bg-white py-2.5 text-xs font-bold text-gray-700 hover:bg-gray-50 transition disabled:opacity-40"
                            >
                                Request Correction from Applicant
                            </button>

                            <button
                                type="button"
                                onClick={handleReject}
                                disabled={isProcessing}
                                className="w-full rounded-xl border border-red-200 bg-red-50 py-2.5 text-xs font-bold text-red-700 hover:bg-red-100 transition disabled:opacity-40"
                            >
                                Reject Application
                            </button>
                        </div>

                        <p className="text-[10px] text-gray-400 text-center">
                            All decisions are recorded in the audit trail.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
