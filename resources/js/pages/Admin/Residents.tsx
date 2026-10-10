import { Head, router } from '@inertiajs/react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type Sector = {
    id: number;
    name: string;
    slug: 'senior' | 'pwd' | string;
};

type RegistrationDocument = {
    id: number;
    type: string;
    label: string;
    mime_type: string | null;
    file_size: number | null;
    verification_status: string;
    url: string;
    is_image: boolean;
};

type PendingRegistration = {
    id: number;
    reference: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    birthdate: string;
    sex: string;
    civil_status: string;
    address_line: string;
    mobile: string;
    email: string | null;
    barangay: string | null;
    purok: string | null;
    sectors: Sector[];
    documents: RegistrationDocument[];
    status: 'pending' | 'needs_correction';
    submitted_at: string | null;
};

type Stats = {
    totalResidents: number;
    activeResidents: number;
    pendingRegistrations: number;
};

type Props = {
    pendingRegistrations?: PendingRegistration[];
    approvedResidents?: Resident[];
    stats?: Stats;
};

type Tab = 'approved' | 'pending';

type ResidentStatus = 'Active' | 'Inactive';

type Resident = {
    id: number;
    residentId: string;
    fullName: string;
    sex: string;
    purok: string;
    sectors: Sector[];
    status: ResidentStatus;
};

export default function Residents({
    pendingRegistrations = [],
    approvedResidents = [],
    stats = {
        totalResidents: 0,
        activeResidents: 0,
        pendingRegistrations: 0,
    },
}: Props) {
    const safePending = Array.isArray(pendingRegistrations)
        ? pendingRegistrations
        : [];

    const safeApproved = Array.isArray(approvedResidents)
        ? approvedResidents
        : [];

    const [activeTab, setActiveTab] = useState<Tab>('pending');
    const [search, setSearch] = useState('');
    const [selectedApplication, setSelectedApplication] =
        useState<PendingRegistration | null>(null);
    const [rejectingApplication, setRejectingApplication] =
        useState<PendingRegistration | null>(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [rejectError, setRejectError] = useState('');
    const [processingReject, setProcessingReject] = useState(false);

    const [processingApproveId, setProcessingApproveId] =
        useState<number | null>(null);
    const [approveError, setApproveError] = useState('');

    const filteredApproved = useMemo(() => {
        const keyword = search.trim().toLowerCase();

        if (!keyword) {
            return safeApproved;
        }

        return safeApproved.filter((resident) => {
            return (
                resident.fullName.toLowerCase().includes(keyword) ||
                resident.residentId.toLowerCase().includes(keyword) ||
                resident.purok.toLowerCase().includes(keyword)
            );
        });
    }, [safeApproved, search]);

    const filteredPending = useMemo(() => {
        const keyword = search.trim().toLowerCase();

        if (!keyword) {
            return safePending;
        }

        return safePending.filter((application) => {
            const searchable = [
                getApplicantName(application),
                application.mobile,
                application.email ?? '',
                application.purok ?? '',
                application.barangay ?? '',
                application.reference,
            ]
                .join(' ')
                .toLowerCase();

            return searchable.includes(keyword);
        });
    }, [safePending, search]);

    const openReject = (application: PendingRegistration) => {
        setSelectedApplication(null);
        setRejectingApplication(application);
        setRejectionReason('');
        setRejectError('');
        setApproveError('');
    };

    const approveRegistration = (application: PendingRegistration) => {
        if (processingApproveId !== null) {
            return;
        }

        const confirmed = window.confirm(
            `Approve ${getApplicantName(application)} as a permanent resident?`,
        );

        if (!confirmed) {
            return;
        }

        setProcessingApproveId(application.id);
        setApproveError('');

        router.post(
            `/admin/residents/${application.id}/approve`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApplication(null);
                    setApproveError('');
                },
                onError: (errors) => {
                    const message =
                        typeof errors.approval === 'string'
                            ? errors.approval
                            : 'Unable to approve this registration. Please review the application and try again.';

                    setApproveError(message);
                },
                onFinish: () => {
                    setProcessingApproveId(null);
                },
            },
        );
    };

    const rejectRegistration = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!rejectingApplication) {
            return;
        }

        const reason = rejectionReason.trim();

        if (reason.length < 5) {
            setRejectError('Please enter a clear rejection reason.');
            return;
        }

        setProcessingReject(true);
        setRejectError('');

        router.post(
            `/admin/residents/${rejectingApplication.id}/reject`,
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectingApplication(null);
                    setRejectionReason('');
                    setRejectError('');
                },
                onError: (errors) => {
                    setRejectError(
                        typeof errors.reason === 'string'
                            ? errors.reason
                            : 'Unable to reject this application.',
                    );
                },
                onFinish: () => {
                    setProcessingReject(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Residents" />

            <AdminLayout active="residents">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Residents
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Review resident registrations and manage approved residents
                    </p>
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <SummaryCard
                        title="Total Residents"
                        value={stats.totalResidents}
                    />
                    <SummaryCard
                        title="Active Residents"
                        value={stats.activeResidents}
                    />
                    <SummaryCard
                        title="Pending Registrations"
                        value={stats.pendingRegistrations}
                        highlight={stats.pendingRegistrations > 0}
                    />
                </div>

                <div className="mb-5 flex flex-col gap-4 border-b border-gray-200 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex overflow-x-auto">
                        <TabButton
                            label="Approved Residents"
                            active={activeTab === 'approved'}
                            onClick={() => {
                                setActiveTab('approved');
                                setSearch('');
                            }}
                        />
                        <TabButton
                            label={`Pending Registrations (${safePending.length})`}
                            active={activeTab === 'pending'}
                            onClick={() => {
                                setActiveTab('pending');
                                setSearch('');
                            }}
                        />
                    </div>

                    <div className="pb-3">
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={
                                activeTab === 'approved'
                                    ? 'Search resident...'
                                    : 'Search applicant...'
                            }
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d] sm:w-72"
                        />
                    </div>
                </div>

                {approveError && (
                    <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                        <p className="text-sm font-medium text-red-700">
                            {approveError}
                        </p>
                    </div>
                )}

                {activeTab === 'approved' && (
                    <ApprovedResidentsTable residents={filteredApproved} />
                )}

                {activeTab === 'pending' && (
                    <PendingRegistrationsTable
                        applications={filteredPending}
                        onView={(application) => {
                            setApproveError('');
                            setSelectedApplication(application);
                        }}
                        onReject={openReject}
                        onApprove={approveRegistration}
                        processingApproveId={processingApproveId}
                    />
                )}

                {selectedApplication && (
                    <ApplicationModal
                        application={selectedApplication}
                        onClose={() => {
                            if (processingApproveId !== null) {
                                return;
                            }

                            setSelectedApplication(null);
                            setApproveError('');
                        }}
                        onReject={() => openReject(selectedApplication)}
                        onApprove={() =>
                            approveRegistration(selectedApplication)
                        }
                        processingApprove={
                            processingApproveId === selectedApplication.id
                        }
                    />
                )}

                {rejectingApplication && (
                    <RejectModal
                        application={rejectingApplication}
                        reason={rejectionReason}
                        setReason={(value) => {
                            setRejectionReason(value);
                            if (rejectError) {
                                setRejectError('');
                            }
                        }}
                        error={rejectError}
                        processing={processingReject}
                        onCancel={() => {
                            if (processingReject) {
                                return;
                            }

                            setRejectingApplication(null);
                            setRejectionReason('');
                            setRejectError('');
                        }}
                        onSubmit={rejectRegistration}
                    />
                )}
            </AdminLayout>
        </>
    );
}

function ApprovedResidentsTable({
    residents,
}: {
    residents: Resident[];
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[900px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Resident ID</TableHead>
                            <TableHead>Resident</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Status</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {residents.map((resident, index) => (
                            <tr
                                key={resident.id}
                                className={
                                    index !== residents.length - 1
                                        ? 'border-b border-gray-100'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4 font-mono text-xs font-semibold text-[#3f6f3d]">
                                    {resident.residentId}
                                </td>
                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-gray-800">
                                        {resident.fullName}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {resident.sex}
                                    </p>
                                </td>
                                <td className="px-5 py-4 text-sm text-gray-600">
                                    {resident.purok}
                                </td>
                                <td className="px-5 py-4">
                                    <SectorBadges sectors={resident.sectors} />
                                </td>
                                <td className="px-5 py-4">
                                    <span className="inline-flex rounded-full bg-[#edf4ea] px-3 py-1 text-xs font-medium text-[#3f6f3d]">
                                        {resident.status}
                                    </span>
                                </td>
                                <td className="px-5 py-4 text-right text-xs text-gray-400">
                                    —
                                </td>
                            </tr>
                        ))}

                        {residents.length === 0 && (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="px-5 py-12 text-center"
                                >
                                    <p className="text-sm font-medium text-gray-600">
                                        No approved residents yet
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        Approved resident accounts will appear here.
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function PendingRegistrationsTable({
    applications,
    onView,
    onReject,
    onApprove,
    processingApproveId,
}: {
    applications: PendingRegistration[];
    onView: (application: PendingRegistration) => void;
    onReject: (application: PendingRegistration) => void;
    onApprove: (application: PendingRegistration) => void;
    processingApproveId: number | null;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[950px]">
                    <thead className="border-b border-gray-200 bg-[#fafaf7]">
                        <tr>
                            <TableHead>Applicant</TableHead>
                            <TableHead>Purok</TableHead>
                            <TableHead>Sector</TableHead>
                            <TableHead>Submitted</TableHead>
                            <th className="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-400">
                                Action
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        {applications.map((application, index) => (
                            <tr
                                key={application.id}
                                className={
                                    index !== applications.length - 1
                                        ? 'border-b border-gray-100'
                                        : ''
                                }
                            >
                                <td className="px-5 py-4">
                                    <p className="text-sm font-medium text-gray-800">
                                        {getApplicantName(application)}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-400">
                                        {application.mobile}
                                    </p>
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-600">
                                    {application.purok || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <SectorBadges sectors={application.sectors} />
                                </td>

                                <td className="px-5 py-4 text-sm text-gray-500">
                                    {application.submitted_at || '—'}
                                </td>

                                <td className="px-5 py-4">
                                    <div className="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            onClick={() => onView(application)}
                                            className="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50"
                                        >
                                            View
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => onReject(application)}
                                            className="rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50"
                                        >
                                            Reject
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => onApprove(application)}
                                            disabled={
                                                processingApproveId !== null
                                            }
                                            className="rounded-md bg-[#3f6f3d] px-3 py-2 text-xs font-medium text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {processingApproveId === application.id
                                                ? 'Approving...'
                                                : 'Approve'}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        ))}

                        {applications.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-5 py-12 text-center"
                                >
                                    <p className="text-sm font-medium text-gray-600">
                                        No pending registrations
                                    </p>
                                    <p className="mt-1 text-xs text-gray-400">
                                        New resident registrations will appear here.
                                    </p>
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function ApplicationModal({
    application,
    onClose,
    onReject,
    onApprove,
    processingApprove,
}: {
    application: PendingRegistration;
    onClose: () => void;
    onReject: () => void;
    onApprove: () => void;
    processingApprove: boolean;
}) {
    return (
        <ModalContainer>
            <div className="w-full max-w-3xl overflow-hidden rounded-xl bg-white shadow-xl">
                <ModalHeader
                    title="Review Registration"
                    description="Verify the resident information before taking action"
                    onClose={onClose}
                />

                <div className="max-h-[75vh] overflow-y-auto p-6">
                    <div className="mb-5 flex flex-wrap gap-2">
                        <span className="rounded-full bg-[#fff4d8] px-3 py-1 text-xs font-medium capitalize text-[#946b00]">
                            {application.status.replace('_', ' ')}
                        </span>

                        <SectorBadges sectors={application.sectors} />
                    </div>

                    <div className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                        <Info
                            label="Full Name"
                            value={getApplicantName(application)}
                        />
                        <Info
                            label="Birthdate"
                            value={application.birthdate || '—'}
                        />
                        <Info label="Sex" value={application.sex || '—'} />
                        <Info
                            label="Civil Status"
                            value={application.civil_status || '—'}
                        />
                        <Info
                            label="Mobile Number"
                            value={application.mobile || '—'}
                        />
                        <Info
                            label="Email"
                            value={application.email || 'Not provided'}
                        />
                        <Info
                            label="Barangay"
                            value={application.barangay || '—'}
                        />
                        <Info
                            label="Purok"
                            value={application.purok || '—'}
                        />

                        <div className="sm:col-span-2">
                            <Info
                                label="Local Address"
                                value={application.address_line || '—'}
                            />
                        </div>

                        <Info
                            label="Submitted"
                            value={application.submitted_at || '—'}
                        />
                        <Info
                            label="Application Reference"
                            value={application.reference}
                        />
                    </div>

                    <div className="mt-6">
                        <div className="mb-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Identity Verification
                            </p>
                            <p className="mt-1 text-xs text-gray-400">
                                Private registration documents. Only authorized barangay administrators can open these files.
                            </p>
                        </div>

                        <ApplicationDocuments
                            documents={
                                Array.isArray(application.documents)
                                    ? application.documents
                                    : []
                            }
                        />
                    </div>
                </div>

                <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-6 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={processingApprove}
                        className="rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Close
                    </button>

                    <button
                        type="button"
                        onClick={onReject}
                        disabled={processingApprove}
                        className="rounded-md border border-red-200 px-4 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Reject
                    </button>

                    <button
                        type="button"
                        onClick={onApprove}
                        disabled={processingApprove}
                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {processingApprove
                            ? 'Approving...'
                            : 'Approve Registration'}
                    </button>
                </div>
            </div>
        </ModalContainer>
    );
}

function ApplicationDocuments({
    documents,
}: {
    documents: RegistrationDocument[];
}) {
    if (documents.length === 0) {
        return (
            <div className="rounded-lg border border-gray-200 bg-[#fafaf7] p-4">
                <p className="text-sm font-medium text-gray-600">
                    No registration documents found.
                </p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {documents.map((document) => (
                <div
                    key={document.id}
                    className="overflow-hidden rounded-lg border border-gray-200 bg-white"
                >
                    <div className="border-b border-gray-100 bg-[#fafaf7] px-4 py-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold text-gray-800">
                                    {document.label}
                                </p>
                                <p className="mt-0.5 text-[11px] text-gray-400">
                                    {document.mime_type || 'Document'}
                                    {document.file_size
                                        ? ` • ${formatFileSize(document.file_size)}`
                                        : ''}
                                </p>
                            </div>

                            <span className="rounded-full bg-[#fff4d8] px-2.5 py-1 text-[10px] font-medium capitalize text-[#946b00]">
                                {document.verification_status.replace('_', ' ')}
                            </span>
                        </div>
                    </div>

                    {document.is_image ? (
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noreferrer"
                            className="block bg-gray-50"
                            title={`Open ${document.label}`}
                        >
                            <img
                                src={document.url}
                                alt={document.label}
                                className="h-56 w-full object-contain p-3"
                            />
                        </a>
                    ) : (
                        <div className="flex h-40 items-center justify-center bg-gray-50 p-4 text-center">
                            <div>
                                <p className="text-sm font-medium text-gray-600">
                                    Document preview
                                </p>
                                <p className="mt-1 text-xs text-gray-400">
                                    Open the file to review it securely.
                                </p>
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end px-4 py-3">
                        <a
                            href={document.url}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-md border border-[#b7d8b2] px-3 py-2 text-xs font-medium text-[#3f6f3d] transition hover:bg-[#edf4ea]"
                        >
                            {document.is_image ? 'View Full Image' : 'Open Document'}
                        </a>
                    </div>
                </div>
            ))}
        </div>
    );
}

function formatFileSize(bytes: number) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function RejectModal({
    application,
    reason,
    setReason,
    error,
    processing,
    onCancel,
    onSubmit,
}: {
    application: PendingRegistration;
    reason: string;
    setReason: (value: string) => void;
    error: string;
    processing: boolean;
    onCancel: () => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    return (
        <ModalContainer>
            <div className="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl">
                <ModalHeader
                    title="Reject Registration"
                    description={getApplicantName(application)}
                    onClose={onCancel}
                />

                <form onSubmit={onSubmit}>
                    <div className="p-5">
                        <div className="mb-4 rounded-lg border border-red-100 bg-red-50 p-3">
                            <p className="text-xs leading-5 text-red-700">
                                A rejection reason is required. This will be saved with the application so the resident can be informed why the request was rejected.
                            </p>
                        </div>

                        <label className="mb-1.5 block text-xs font-medium text-gray-700">
                            Reason for rejection
                            <span className="ml-1 text-red-500">*</span>
                        </label>

                        <textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                            maxLength={1000}
                            placeholder="Example: Valid ID is unclear. Please submit a clearer copy."
                            className={[
                                inputClass,
                                'resize-none',
                                error ? 'border-red-300' : '',
                            ].join(' ')}
                            autoFocus
                        />

                        <div className="mt-1.5 flex items-start justify-between gap-3">
                            <div>
                                {error && (
                                    <p className="text-xs font-medium text-red-600">
                                        {error}
                                    </p>
                                )}
                            </div>
                            <p className="shrink-0 text-[11px] text-gray-400">
                                {reason.length}/1000
                            </p>
                        </div>
                    </div>

                    <div className="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={processing}
                            className="rounded-md border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            disabled={
                                processing || rejectionReasonIsInvalid(reason)
                            }
                            className="rounded-md bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing
                                ? 'Rejecting...'
                                : 'Reject Registration'}
                        </button>
                    </div>
                </form>
            </div>
        </ModalContainer>
    );
}

const inputClass =
    'w-full rounded-md border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d]';

function SummaryCard({
    title,
    value,
    highlight = false,
}: {
    title: string;
    value: number;
    highlight?: boolean;
}) {
    return (
        <div
            className={`rounded-lg border p-5 ${
                highlight
                    ? 'border-[#b7d8b2] bg-[#edf4ea]'
                    : 'border-gray-200 bg-white'
            }`}
        >
            <p
                className={`text-xs font-medium uppercase tracking-wide ${
                    highlight ? 'text-[#3f6f3d]' : 'text-gray-400'
                }`}
            >
                {title}
            </p>
            <p className="mt-3 text-3xl font-semibold text-gray-800">
                {value}
            </p>
        </div>
    );
}

function TabButton({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium ${
                active
                    ? 'border-[#3f6f3d] text-[#3f6f3d]'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
        >
            {label}
        </button>
    );
}

function TableHead({ children }: { children: ReactNode }) {
    return (
        <th className="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-400">
            {children}
        </th>
    );
}

function SectorBadges({ sectors }: { sectors: Sector[] }) {
    const safeSectors = Array.isArray(sectors) ? sectors : [];

    if (safeSectors.length === 0) {
        return <span className="text-xs text-gray-400">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {safeSectors.map((sector) => {
                if (sector.slug === 'senior') {
                    return (
                        <span
                            key={sector.id}
                            className="rounded-full bg-[#edf4ea] px-2.5 py-1 text-xs font-medium text-[#3f6f3d]"
                        >
                            Senior
                        </span>
                    );
                }

                if (sector.slug === 'pwd') {
                    return (
                        <span
                            key={sector.id}
                            className="rounded-full bg-[#eef3fb] px-2.5 py-1 text-xs font-medium text-[#416b9c]"
                        >
                            PWD
                        </span>
                    );
                }

                return (
                    <span
                        key={sector.id}
                        className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600"
                    >
                        {sector.name}
                    </span>
                );
            })}
        </div>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                {label}
            </p>
            <p className="mt-1 break-words text-sm font-medium text-gray-800">
                {value}
            </p>
        </div>
    );
}

function ModalContainer({ children }: { children: ReactNode }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
            {children}
        </div>
    );
}

function ModalHeader({
    title,
    description,
    onClose,
}: {
    title: string;
    description: string;
    onClose: () => void;
}) {
    return (
        <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4">
            <div>
                <h2 className="text-lg font-semibold text-gray-800">
                    {title}
                </h2>
                <p className="mt-1 text-xs text-gray-500">{description}</p>
            </div>

            <button
                type="button"
                onClick={onClose}
                className="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100"
                aria-label="Close"
            >
                ×
            </button>
        </div>
    );
}

function getApplicantName(application: PendingRegistration) {
    return [
        application.first_name,
        application.middle_name,
        application.last_name,
        application.suffix,
    ]
        .filter(Boolean)
        .join(' ');
}

function rejectionReasonIsInvalid(reason: string) {
    return reason.trim().length < 5;
}
