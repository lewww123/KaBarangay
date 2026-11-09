import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState, FormEvent } from 'react';
import type { SectorApplicantResident, SectorType } from './types';

interface Props {
    sector: SectorType;
    cancelUrl: string;
    onApplicationSubmitted?: (applicationData: any) => void;
}

export default function SectorApplicationWizard({ sector, cancelUrl }: Props) {
    const sectorTitle =
        sector === 'senior' ? 'Senior Citizen' : 'Person with Disability (PWD)';
    const sectorSlug = sector;

    // STEP 1: Search & Lookup state
    const [lookupQuery, setLookupQuery] = useState('');
    const [lookupLoading, setLookupLoading] = useState(false);
    const [lookupError, setLookupError] = useState<string | null>(null);
    const [matchedResident, setMatchedResident] =
        useState<SectorApplicantResident | null>(null);
    const [isConfirmedResident, setIsConfirmedResident] = useState(false);

    // STEP 2: Application Form State
    const [applicationNotes, setApplicationNotes] = useState('');
    const [documents, setDocuments] = useState<
        {
            name: string;
            type: string;
            file: File | null;
        }[]
    >([
        {
            name:
                sector === 'senior'
                    ? 'Proof of Age / Senior ID'
                    : 'Certificate of Disability / Medical Proof',
            type: sector === 'senior' ? 'senior_proof' : 'pwd_proof',
            file: null,
        },
        {
            name: 'Barangay Residency Certification / Valid ID',
            type: 'valid_id',
            file: null,
        },
    ]);

    const [isSubmitting, setIsSubmitting] = useState(false);
    const [submittedSuccess, setSubmittedSuccess] = useState(false);
    const [submissionError, setSubmissionError] = useState<string | null>(null);

    const lookupInputRef = useRef<HTMLInputElement | null>(null);

    // Keyboard-wedge scanners send keystrokes to the focused control. Focus
    // the lookup field when this step appears; manual entry remains editable.
    useEffect(() => {
        lookupInputRef.current?.focus({ preventScroll: true });
    }, []);

    // Call existing QR verify-token API contract
    const handleResidentLookup = async (overrideToken?: string) => {
        const token = (
            overrideToken !== undefined ? overrideToken : lookupQuery
        ).trim();

        if (!token) {
            setLookupError(
                'Please scan a QR code or enter a valid Resident Number.',
            );
            return;
        }

        setLookupLoading(true);
        setLookupError(null);
        setMatchedResident(null);
        setIsConfirmedResident(false);

        try {
            const response = await axios.get(
                `/api/qr/verify-token?token=${encodeURIComponent(token)}`,
            );
            const resident = response.data.resident;

            if (!resident) {
                setLookupError(
                    'No active resident found matching this QR code or Resident Number.',
                );
                setLookupLoading(false);
                return;
            }

            // Safe structured resident data from verified API
            const verifiedApplicant: SectorApplicantResident = {
                id: resident.id,
                resident_number: resident.resident_number,
                full_name: resident.full_name,
                purok: resident.purok?.name || resident.purok || 'San Jose',
                sex: resident.sex || '—',
                existing_sectors: resident.sectors || [],
            };

            setMatchedResident(verifiedApplicant);
        } catch (err: any) {
            if (err.response?.status === 404) {
                setLookupError(
                    'Resident not found or account is not in active status in this barangay.',
                );
            } else if (err.response?.data?.message) {
                setLookupError(err.response.data.message);
            } else {
                setLookupError(
                    'Unable to verify resident credentials. Please check connection and try again.',
                );
            }
        } finally {
            setLookupLoading(false);
        }
    };

    const isAlreadyMember = matchedResident?.existing_sectors?.some(
        (s) => s.slug?.toLowerCase() === sectorSlug,
    );

    const handleFileChange = (index: number, file: File | null) => {
        setDocuments((prev) => {
            const updated = [...prev];
            updated[index] = { ...updated[index], file };
            return updated;
        });
    };

    const handleSubmitApplication = (e: FormEvent) => {
        e.preventDefault();
        if (!matchedResident || !isConfirmedResident || isAlreadyMember) return;

        setIsSubmitting(true);
        setSubmissionError(null);

        const formData = new FormData();
        formData.append('resident_id', String(matchedResident.id));
        if (applicationNotes) {
            formData.append(
                sector === 'senior' ? 'osca_id' : 'disability_type',
                applicationNotes,
            );
        }
        documents.forEach((doc) => {
            if (doc.file) {
                formData.append('documents[]', doc.file);
            }
        });

        router.post(
            sector === 'senior' ? '/senior/applications' : '/pwd/applications',
            formData,
            {
                forceFormData: true,
                onSuccess: () => {
                    setIsSubmitting(false);
                    setSubmittedSuccess(true);
                },
                onError: (errors) => {
                    setIsSubmitting(false);
                    setSubmissionError(Object.values(errors).flat().join(' '));
                },
            },
        );
    };

    return (
        <div className="mx-auto max-w-4xl space-y-6">
            {/* BREADCRUMB / HEADER */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div className="flex items-center gap-2 text-xs font-semibold tracking-wider text-ink-muted uppercase">
                        <Link href={cancelUrl} className="hover:text-ink-strong">
                            {sectorTitle} Desk
                        </Link>
                        <span>/</span>
                        <span className="font-semibold text-ink-strong">
                            New Application
                        </span>
                    </div>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight text-ink-strong">
                        Staff-Assisted {sectorTitle} Application
                    </h1>
                </div>

                <Link
                    href={cancelUrl}
                    className="inline-flex items-center text-xs font-semibold text-ink-muted hover:text-ink-strong"
                >
                    ← Back to Applications
                </Link>
            </div>

            {submissionError && (
                <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-900 shadow-card">
                    ⚠️ {submissionError}
                </div>
            )}

            {submittedSuccess ? (
                /* SUCCESS STATE */
                <div className="space-y-4 rounded-xl border border-emerald-200 bg-white p-8 text-center shadow-card">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-700">
                        ✓
                    </div>
                    <h2 className="text-xl font-semibold text-ink-strong">
                        Application Intake Completed
                    </h2>
                    <p className="mx-auto max-w-md text-sm text-ink-muted">
                        {sectorTitle} application for{' '}
                        <strong className="text-ink-strong">
                            {matchedResident?.full_name}
                        </strong>{' '}
                        ({matchedResident?.resident_number}) has been prepared
                        for staff completeness review and forwarding to the
                        Barangay Admin.
                    </p>
                    <div className="flex justify-center gap-3 pt-4">
                        <button
                            type="button"
                            onClick={() => {
                                setMatchedResident(null);
                                setIsConfirmedResident(false);
                                setLookupQuery('');
                                setSubmittedSuccess(false);
                            }}
                            className="rounded-xl border border-line bg-white px-4 py-2 text-xs font-semibold text-ink shadow-card hover:bg-canvas"
                        >
                            + Process Another Resident
                        </button>
                        <Link
                            href={cancelUrl}
                            className="rounded-xl bg-brand px-4 py-2 text-xs font-semibold text-white shadow-card hover:bg-brand-deep"
                        >
                            View Applications Queue →
                        </Link>
                    </div>
                </div>
            ) : (
                <div className="space-y-6">
                    {/* STEP 1: SCAN QR CODE / SEARCH RESIDENT */}
                    <div className="space-y-5 rounded-xl border border-line-soft bg-white p-6 shadow-card">
                        <div className="flex items-center justify-between border-b border-line-soft pb-3">
                            <h2 className="flex items-center gap-2 text-base font-semibold text-ink-strong">
                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs text-white">
                                    1
                                </span>
                                Resident Identification & Verification
                            </h2>
                            <span className="text-xs text-ink-faint">
                                USB / Bluetooth Scanner Ready
                            </span>
                        </div>

                        <div className="space-y-3">
                            <label className="block text-xs font-semibold tracking-wider text-ink-muted uppercase">
                                Scan Resident QR Code or Type Resident ID
                            </label>
                            <div className="flex gap-2">
                                <div className="relative flex-1">
                                    <input
                                        ref={lookupInputRef}
                                        type="text"
                                        value={lookupQuery}
                                        onChange={(e) =>
                                            setLookupQuery(e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                void handleResidentLookup();
                                            }
                                        }}
                                        placeholder="Scan QR or enter BSJ-RES-000001 / raw token..."
                                        className="w-full rounded-xl border border-line bg-canvas/50 px-4 py-2.5 font-mono text-sm text-ink-strong placeholder-gray-400 focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                                    />
                                    {lookupQuery && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setLookupQuery('');
                                                setMatchedResident(null);
                                                setIsConfirmedResident(false);
                                                setLookupError(null);
                                            }}
                                            className="absolute top-1/2 right-3 -translate-y-1/2 text-xs font-semibold text-ink-faint hover:text-ink-muted"
                                        >
                                            ✕
                                        </button>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() => handleResidentLookup()}
                                    disabled={lookupLoading}
                                    className="rounded-xl bg-brand px-5 py-2.5 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep disabled:opacity-50"
                                >
                                    {lookupLoading
                                        ? 'Verifying...'
                                        : 'Verify Resident'}
                                </button>
                            </div>
                            <p className="text-[11px] text-ink-faint">
                                Compatible with physical 2D barcode scanners,
                                keyboard-wedge devices, and manual lookup.
                            </p>
                        </div>

                        {lookupError && (
                            <div className="rounded-xl border border-red-200 bg-red-50/70 p-3.5 text-xs text-red-800">
                                <strong>Verification Error:</strong>{' '}
                                {lookupError}
                            </div>
                        )}

                        {/* MATCHED RESIDENT CARD */}
                        {matchedResident && (
                            <div
                                className={`rounded-xl border p-4.5 transition ${
                                    isAlreadyMember
                                        ? 'border-amber-200 bg-amber-50/50'
                                        : 'border-emerald-200 bg-emerald-50/30'
                                }`}
                            >
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="rounded border border-line-soft bg-white px-2 py-0.5 font-mono text-xs font-semibold text-ink-muted">
                                                {
                                                    matchedResident.resident_number
                                                }
                                            </span>
                                            <h3 className="text-base font-semibold text-ink-strong">
                                                {matchedResident.full_name}
                                            </h3>
                                        </div>
                                        <p className="text-xs text-ink-muted">
                                            Barangay San Jose • Purok:{' '}
                                            <strong className="text-ink-strong">
                                                {matchedResident.purok}
                                            </strong>{' '}
                                            • Sex:{' '}
                                            <strong className="text-ink-strong">
                                                {matchedResident.sex}
                                            </strong>
                                        </p>
                                    </div>

                                    <div>
                                        {isAlreadyMember ? (
                                            <span className="inline-flex rounded-lg border border-amber-300 bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800">
                                                Already Active {sectorTitle}
                                            </span>
                                        ) : (
                                            <span className="inline-flex rounded-lg border border-emerald-300 bg-emerald-100 px-3 py-1.5 text-xs font-semibold text-emerald-800">
                                                Active Resident Verified
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {isAlreadyMember ? (
                                    <p className="mt-3 text-xs font-medium text-amber-900">
                                        ⚠️ This resident is already recognized
                                        in the {sectorTitle} sector. Duplicate
                                        sector registration is prevented.
                                    </p>
                                ) : (
                                    <div className="mt-4 flex items-center gap-2 border-t border-emerald-100/80 pt-3">
                                        <input
                                            type="checkbox"
                                            id="confirm_identity"
                                            checked={isConfirmedResident}
                                            onChange={(e) =>
                                                setIsConfirmedResident(
                                                    e.target.checked,
                                                )
                                            }
                                            className="h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                        />
                                        <label
                                            htmlFor="confirm_identity"
                                            className="cursor-pointer text-xs font-semibold text-ink"
                                        >
                                            I confirm this is the resident
                                            requesting staff-assisted{' '}
                                            {sectorTitle} application.
                                        </label>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* STEP 2: APPLICATION FORM & DOCUMENTS */}
                    <form
                        onSubmit={handleSubmitApplication}
                        className="space-y-6 rounded-xl border border-line-soft bg-white p-6 shadow-card"
                    >
                        <div className="border-b border-line-soft pb-3">
                            <h2 className="flex items-center gap-2 text-base font-semibold text-ink-strong">
                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gray-900 text-xs text-white">
                                    2
                                </span>
                                Application Details & Supporting Documents
                            </h2>
                            <p className="mt-1 text-xs text-ink-muted">
                                Attach required proofs for completeness review
                                prior to forwarding to the Barangay Admin.
                            </p>
                        </div>

                        {/* SUPPORTING DOCUMENTS UPLOAD */}
                        <div className="space-y-4">
                            <label className="block text-xs font-semibold tracking-wider text-ink uppercase">
                                Required Sector Documentation
                            </label>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {documents.map((doc, idx) => (
                                    <div
                                        key={doc.type}
                                        className="space-y-2.5 rounded-xl border border-line-soft bg-canvas/50 p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-semibold text-ink-strong">
                                                {doc.name}
                                            </span>
                                            <span className="text-[10px] font-semibold text-ink-faint uppercase">
                                                Required
                                            </span>
                                        </div>

                                        <input
                                            type="file"
                                            accept="image/*,.pdf"
                                            disabled={
                                                !isConfirmedResident ||
                                                isAlreadyMember
                                            }
                                            onChange={(e) =>
                                                handleFileChange(
                                                    idx,
                                                    e.target.files?.[0] || null,
                                                )
                                            }
                                            className="block w-full text-xs text-ink-muted file:mr-2 file:rounded-lg file:border-0 file:bg-line-soft file:px-2.5 file:py-1.5 file:text-xs file:font-semibold file:text-ink hover:file:bg-gray-300 disabled:opacity-50"
                                        />

                                        {doc.file && (
                                            <p className="text-[11px] font-medium text-emerald-700">
                                                ✓ Selected: {doc.file.name} (
                                                {(doc.file.size / 1024).toFixed(
                                                    1,
                                                )}{' '}
                                                KB)
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* STAFF NOTES / REMARKS */}
                        <div className="space-y-2">
                            <label className="block text-xs font-semibold tracking-wider text-ink uppercase">
                                Staff Intake Notes & Initial Remarks (Optional)
                            </label>
                            <textarea
                                rows={3}
                                value={applicationNotes}
                                onChange={(e) =>
                                    setApplicationNotes(e.target.value)
                                }
                                disabled={
                                    !isConfirmedResident || isAlreadyMember
                                }
                                placeholder="Add observations on submitted proofs, physical presence, or special accommodation requirements..."
                                className="w-full rounded-xl border border-line bg-canvas/50 p-3 text-xs text-ink-strong placeholder-gray-400 focus:border-brand focus:bg-white focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none disabled:opacity-50"
                            />
                        </div>

                        {/* SUBMIT BUTTON */}
                        <div className="flex items-center justify-end gap-3 border-t border-line-soft pt-4">
                            <Link
                                href={cancelUrl}
                                className="rounded-xl border border-line px-4 py-2.5 text-xs font-semibold text-ink transition hover:bg-canvas"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                disabled={
                                    !isConfirmedResident ||
                                    isAlreadyMember ||
                                    isSubmitting
                                }
                                className="inline-flex items-center gap-2 rounded-xl bg-brand px-5 py-2.5 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep disabled:opacity-50"
                            >
                                {isSubmitting
                                    ? 'Processing Application...'
                                    : 'Submit Application for Review'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
