import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import type {
    SectorApplicationItem,
    SectorType,
} from '@/components/sectors/types';

interface Props {
    resident: {
        id: number;
        full_name: string;
        resident_number: string;
        barangay?: string;
        sectors?: {
            id: number;
            name: string;
            slug: string;
        }[];
    };
    applications: SectorApplicationItem[];
}

export default function ResidentSectorApplicationsIndex(props: Props) {
    return (
        <Deferred
            data="applications"
            fallback={
                <ResidentLayout active="sectors">
                    <PortalPageSkeleton list />
                </ResidentLayout>
            }
        >
            <SectorApplicationsContent {...props} />
        </Deferred>
    );
}

function SectorApplicationsContent({ resident, applications }: Props) {
    const [isApplyModalOpen, setIsApplyModalOpen] = useState(false);
    const [selectedSector, setSelectedSector] = useState<SectorType>('senior');
    const [selfServiceNotes, setSelfServiceNotes] = useState('');
    const [fileUploaded, setFileUploaded] = useState<File | null>(null);
    const [applicationSent, setApplicationSent] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const existingSectors = resident.sectors || [];
    const hasSenior = existingSectors.some((s) => s.slug === 'senior');
    const hasPwd = existingSectors.some((s) => s.slug === 'pwd');

    const handleApply = (e: React.FormEvent) => {
        e.preventDefault();
        setApplicationSent(true);
        setSubmitError(null);

        const formData = new FormData();
        formData.append('sector', selectedSector);
        if (selfServiceNotes) {
            formData.append('details[notes]', selfServiceNotes);
        }
        if (fileUploaded) {
            formData.append('documents[]', fileUploaded);
        }

        router.post('/resident/sector-applications', formData, {
            forceFormData: true,
            onSuccess: () => {
                setApplicationSent(false);
                setIsApplyModalOpen(false);
                setSelfServiceNotes('');
                setFileUploaded(null);
            },
            onError: (errors) => {
                setApplicationSent(false);
                setSubmitError(Object.values(errors).flat().join('\n'));
            },
        });
    };

    return (
        <ResidentLayout active="sectors">
            <Head title="Sector Memberships & Applications" />

            <div className="mx-auto max-w-4xl space-y-6 p-4 font-sans sm:p-6">
                {/* HEADER */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-black tracking-tight text-gray-900">
                            Sector Memberships & Services
                        </h1>
                        <p className="mt-1 text-sm text-gray-600">
                            View accredited sector privileges, access welfare
                            programs, or submit an application.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={() => setIsApplyModalOpen(true)}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#3f6f3d] px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-[#315b30]"
                    >
                        <span>+</span>
                        Apply for Sector Membership
                    </button>
                </div>

                {/* CURRENT SECTOR MEMBERSHIPS CARD */}
                <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 className="border-b border-gray-100 pb-3 text-base font-bold text-gray-900">
                        Your Active Accreditations
                    </h2>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {/* GENERAL RESIDENT (ALWAYS ACTIVE) */}
                        <div className="space-y-1 rounded-xl border border-emerald-200 bg-emerald-50/50 p-4">
                            <span className="text-[10px] font-bold tracking-wider text-emerald-800 uppercase">
                                Primary Status
                            </span>
                            <h3 className="text-base font-extrabold text-emerald-950">
                                General Resident
                            </h3>
                            <p className="text-xs text-emerald-800">
                                Active citizen in Barangay San Jose (
                                {resident.resident_number})
                            </p>
                        </div>

                        {/* SENIOR CITIZEN */}
                        <div
                            className={`space-y-1 rounded-xl border p-4 ${
                                hasSenior
                                    ? 'border-amber-200 bg-amber-50/50 text-amber-950'
                                    : 'border-gray-200 bg-gray-50/50 text-gray-400'
                            }`}
                        >
                            <span
                                className={`text-[10px] font-bold tracking-wider uppercase ${
                                    hasSenior
                                        ? 'text-amber-800'
                                        : 'text-gray-400'
                                }`}
                            >
                                Senior Desk
                            </span>
                            <h3 className="text-base font-extrabold">
                                Senior Citizen
                            </h3>
                            <p className="text-xs">
                                {hasSenior
                                    ? 'Accredited member • Eligible for social pension and senior benefits.'
                                    : 'Not registered in this sector.'}
                            </p>
                        </div>

                        {/* PWD */}
                        <div
                            className={`space-y-1 rounded-xl border p-4 ${
                                hasPwd
                                    ? 'border-indigo-200 bg-indigo-50/50 text-indigo-950'
                                    : 'border-gray-200 bg-gray-50/50 text-gray-400'
                            }`}
                        >
                            <span
                                className={`text-[10px] font-bold tracking-wider uppercase ${
                                    hasPwd ? 'text-indigo-800' : 'text-gray-400'
                                }`}
                            >
                                PWD Desk
                            </span>
                            <h3 className="text-base font-extrabold">
                                Person with Disability
                            </h3>
                            <p className="text-xs">
                                {hasPwd
                                    ? 'Accredited member • Eligible for assistive welfare and PWD programs.'
                                    : 'Not registered in this sector.'}
                            </p>
                        </div>
                    </div>

                    <div className="rounded-xl border border-gray-100 bg-gray-50/60 p-3 text-xs text-gray-500">
                        🛡️ <strong>Baseline Access Safeguard:</strong> Applying
                        for or awaiting approval for sector accreditation never
                        alters or restricts your active General Resident portal
                        privileges or document request services.
                    </div>
                </div>

                {/* MY SECTOR APPLICATIONS QUEUE */}
                <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 className="border-b border-gray-100 pb-3 text-base font-bold text-gray-900">
                        My Sector Applications & Review Status
                    </h2>

                    {applications.length === 0 ? (
                        <div className="space-y-1 py-8 text-center text-sm text-gray-500">
                            <p className="font-semibold text-gray-700">
                                No pending sector applications
                            </p>
                            <p className="text-xs text-gray-400">
                                You do not currently have any active or pending
                                sector membership applications.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {applications.map((app) => (
                                <div
                                    key={app.id}
                                    className="space-y-2 rounded-xl border border-gray-200 bg-gray-50/50 p-4 text-xs"
                                >
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <span className="rounded border border-gray-200 bg-white px-2 py-0.5 font-mono text-[10px] font-bold text-gray-500">
                                                {app.tracking_number}
                                            </span>
                                            <h3 className="mt-1 text-sm font-bold text-gray-900">
                                                Application for{' '}
                                                {app.sector_name}
                                            </h3>
                                        </div>

                                        <div>
                                            <span
                                                className={`rounded-md px-2.5 py-1 text-xs font-bold uppercase ${
                                                    app.status === 'approved'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : app.status ===
                                                            'under_admin_review'
                                                          ? 'bg-blue-100 text-blue-800'
                                                          : app.status ===
                                                              'rejected'
                                                            ? 'bg-red-100 text-red-800'
                                                            : 'bg-amber-100 text-amber-800'
                                                }`}
                                            >
                                                {app.status.replace(/_/g, ' ')}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap gap-4 pt-1 text-[11px] text-gray-500">
                                        <span>
                                            Submitted:{' '}
                                            <strong>{app.submitted_at}</strong>
                                        </span>
                                        <span>
                                            Mode:{' '}
                                            <strong>
                                                {app.submission_type ===
                                                'staff_assisted'
                                                    ? 'Staff-Assisted'
                                                    : 'Self-Service'}
                                            </strong>
                                        </span>
                                    </div>

                                    {app.staff_review_notes && (
                                        <p className="rounded-lg border border-gray-200 bg-white p-2.5 text-[11px] text-gray-600">
                                            <strong>Staff Review Note:</strong>{' '}
                                            {app.staff_review_notes}
                                        </p>
                                    )}

                                    {app.status === 'requires_correction' && (
                                        <div className="pt-1">
                                            <Link
                                                href={`/resident/sector-applications/${app.id}/edit`}
                                                className="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-amber-700"
                                            >
                                                ✏️ Correct &amp; Resubmit
                                            </Link>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* APPLICATION MODAL */}
            {isApplyModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-lg space-y-5 rounded-2xl bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between border-b border-gray-100 pb-3">
                            <h3 className="text-base font-bold text-gray-900">
                                Self-Service Sector Membership Application
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsApplyModalOpen(false)}
                                className="font-bold text-gray-400 hover:text-gray-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form
                            onSubmit={handleApply}
                            className="space-y-4 text-xs"
                        >
                            {submitError && (
                                <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-bold text-red-900">
                                    ⚠️ {submitError}
                                </div>
                            )}
                            <div>
                                <label className="mb-1 block text-[11px] font-bold tracking-wider text-gray-600 uppercase">
                                    Select Sector Classification
                                </label>
                                <select
                                    value={selectedSector}
                                    onChange={(e) =>
                                        setSelectedSector(
                                            e.target.value as SectorType,
                                        )
                                    }
                                    className="w-full rounded-xl border border-gray-300 p-2.5 text-xs text-gray-800 focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d] focus:outline-none"
                                >
                                    <option value="senior" disabled={hasSenior}>
                                        Senior Citizen{' '}
                                        {hasSenior
                                            ? '(Already Accredited)'
                                            : ''}
                                    </option>
                                    <option value="pwd" disabled={hasPwd}>
                                        Person with Disability (PWD){' '}
                                        {hasPwd ? '(Already Accredited)' : ''}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label className="mb-1 block text-[11px] font-bold tracking-wider text-gray-600 uppercase">
                                    Upload Supporting Proof (Photo / PDF)
                                </label>
                                <input
                                    type="file"
                                    accept="image/*,.pdf"
                                    onChange={(e) =>
                                        setFileUploaded(
                                            e.target.files?.[0] || null,
                                        )
                                    }
                                    className="block w-full text-xs text-gray-500 file:mr-2 file:rounded-lg file:border-0 file:bg-gray-100 file:px-2.5 file:py-1.5 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200"
                                />
                                <span className="mt-1 block text-[10px] text-gray-400">
                                    Accepts birth certificate, government ID, or
                                    medical evaluation certificate.
                                </span>
                            </div>

                            <div>
                                <label className="mb-1 block text-[11px] font-bold tracking-wider text-gray-600 uppercase">
                                    Applicant Remarks / Special Accommodations
                                </label>
                                <textarea
                                    rows={3}
                                    value={selfServiceNotes}
                                    onChange={(e) =>
                                        setSelfServiceNotes(e.target.value)
                                    }
                                    placeholder="Provide any additional details for the desk staff..."
                                    className="w-full rounded-xl border border-gray-300 p-2.5 text-xs text-gray-800 focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d] focus:outline-none"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 border-t border-gray-100 pt-3">
                                <button
                                    type="button"
                                    onClick={() => setIsApplyModalOpen(false)}
                                    className="rounded-xl border border-gray-300 px-4 py-2 text-xs font-bold text-gray-600 hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={applicationSent}
                                    className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-xs font-bold text-white hover:bg-[#315b30]"
                                >
                                    {applicationSent
                                        ? 'Submitting...'
                                        : 'Submit Application'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ResidentLayout>
    );
}
