import PromoteComplaint from '@/components/lupon/PromoteComplaint';
import { EvidenceList } from '@/components/lupon/Evidence';
import type {
    LuponOfficials,
    LuponEvidenceFile,
} from '@/components/lupon/types';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type ComplaintStatus =
    | 'Submitted'
    | 'Under Review'
    | 'For Payment'
    | 'Accepted'
    | 'Rejected';

type Complaint = {
    id: number;
    reference: string;
    resident: { name: string; residentNumber?: string | null };
    respondentName: string;
    respondentResidentStatus: string;
    respondentAddress?: string | null;
    respondentContact?: string | null;
    complaintType: string;
    incidentDate: string;
    incidentTime?: string | null;
    incidentLocation: string;
    narrative: string;
    requestedAction: string;
    evidence: LuponEvidenceFile[];
    status: ComplaintStatus;
    luponNote?: string | null;
    assessedFee?: string | null;
    submittedDate: string;
    case?: {
        id: number;
        caseNumber: string;
        phase: string;
        status: string;
    } | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type PageProps = {
    auth: {
        user: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
    officials: LuponOfficials;
    userName?: string;
    barangayName?: string | null;
    complaints: Paginator<Complaint>;
    statuses: ComplaintStatus[];
    activeStatusFilter: ComplaintStatus | null;
    stats: {
        pendingCount: number;
        byStatus: Record<ComplaintStatus, number>;
    };
    flash?: { success?: string | null };
};

export default withDeferredPage(
    LuponComplaints,
    ['complaints', 'stats'],
    (props) => (
        <LuponLayout
            active="complaints"
            user={props.auth.user}
            barangayName={props.barangayName}
        >
            <PortalPageSkeleton metrics={4} />
        </LuponLayout>
    ),
);

function LuponComplaints({
    auth,
    userName,
    officials,
    barangayName = null,
    complaints,
    statuses,
    activeStatusFilter,
    stats,
    flash,
}: PageProps) {
    const [selected, setSelected] = useState<Complaint | null>(null);
    const [note, setNote] = useState('');
    const [actionError, setActionError] = useState<string | null>(null);
    const [pendingAction, setPendingAction] = useState(false);
    const [assessedFee, setAssessedFee] = useState('');
    useEffect(() => {
        setSelected((current) =>
            current
                ? (complaints.data.find((item) => item.id === current.id) ??
                  null)
                : null,
        );
    }, [complaints]);

    const requestPayment = (complaint: Complaint) => {
        if (!assessedFee || Number(assessedFee) <= 0) {
            setActionError(
                'Enter the actual assessed filing fee before requesting payment.',
            );
            return;
        }
        setActionError(null);
        setPendingAction(true);
        router.post(
            `/lupon/complaints/${complaint.id}/request-payment`,
            {
                assessed_fee: assessedFee,
                lupon_note: note.trim() || null,
            },
            {
                only: ['complaints', 'stats', 'flash', 'errors'],
                preserveScroll: true,
                preserveState: true,
                showProgress: false,
                onSuccess: () => {
                    setNote('');
                    setAssessedFee('');
                },
                onError: (errors) =>
                    setActionError(Object.values(errors).join(' ')),
                onFinish: () => setPendingAction(false),
            },
        );
    };

    const applyFilter = (status: ComplaintStatus | null) => {
        setSelected(null);

        router.get(
            status
                ? `/lupon/complaints?status=${encodeURIComponent(status)}`
                : '/lupon/complaints',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['complaints', 'activeStatusFilter'],
            },
        );
    };

    const submitStatus = (complaint: Complaint, status: ComplaintStatus) => {
        if (status === 'Rejected' && note.trim() === '') {
            setActionError('A note is required when rejecting a complaint.');
            return;
        }

        setActionError(null);
        setPendingAction(true);

        router.patch(
            `/lupon/complaints/${complaint.id}/status`,
            {
                status,
                lupon_note: note.trim() === '' ? null : note.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
                showProgress: false,
                only: ['complaints', 'stats', 'flash', 'errors'],
                onError: (errors) =>
                    setActionError(Object.values(errors).join(' ')),
                onSuccess: () => {
                    setNote('');
                    setSelected(null);
                },
                onFinish: () => {
                    setPendingAction(false);
                },
            },
        );
    };

    return (
        <LuponLayout
            active="complaints"
            user={auth.user}
            barangayName={barangayName}
        >
            <Head title="Lupon Complaints" />

            <div className="mx-auto max-w-7xl space-y-6 p-2 font-sans sm:p-4">
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Complaints
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Review complaints, request payment, and record the OR
                        before creating a case
                        {userName ? ` — signed in as ${userName}` : ''}.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <StatPill
                            label="Pending review"
                            value={stats.pendingCount}
                        />

                        {statuses.map((status) => (
                            <StatPill
                                key={status}
                                label={status}
                                value={stats.byStatus[status] ?? 0}
                            />
                        ))}
                    </div>
                </div>

                {flash?.success && (
                    <div
                        role="status"
                        aria-live="polite"
                        className="rounded-2xl border border-green-200 bg-green-50 p-4 text-sm text-green-800"
                    >
                        {flash.success}
                    </div>
                )}

                {pendingAction && (
                    <p
                        role="status"
                        aria-live="polite"
                        className="text-sm text-gray-600"
                    >
                        Saving complaint action…
                    </p>
                )}

                <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <FilterChip
                            label="All"
                            active={activeStatusFilter === null}
                            onClick={() => applyFilter(null)}
                        />

                        {statuses.map((status) => (
                            <FilterChip
                                key={status}
                                label={status}
                                active={activeStatusFilter === status}
                                onClick={() => applyFilter(status)}
                            />
                        ))}
                    </div>
                </div>

                {complaints.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-gray-300 bg-[#fafaf7] p-10 text-center">
                        <h2 className="text-base font-semibold text-gray-800">
                            No complaints found
                        </h2>
                        <p className="mx-auto mt-2 max-w-xl text-sm text-gray-500">
                            {activeStatusFilter
                                ? `There are no complaints with the status "${activeStatusFilter}" in this barangay.`
                                : 'No complaints have been submitted in this barangay yet.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {complaints.data.map((complaint) => (
                            <ComplaintRow
                                key={complaint.id}
                                complaint={complaint}
                                expanded={selected?.id === complaint.id}
                                onToggle={() => {
                                    setSelected(
                                        selected?.id === complaint.id
                                            ? null
                                            : complaint,
                                    );
                                    setNote('');
                                    setActionError(null);
                                    setAssessedFee('');
                                }}
                            />
                        ))}
                    </div>
                )}

                {complaints.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-gray-200 pt-4 text-sm">
                        <button
                            type="button"
                            disabled={!complaints.prev_page_url}
                            onClick={() =>
                                complaints.prev_page_url &&
                                router.visit(complaints.prev_page_url, {
                                    preserveScroll: true,
                                })
                            }
                            className="rounded-md border border-gray-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Previous
                        </button>
                        <span className="text-gray-600">
                            Page {complaints.current_page} of{' '}
                            {complaints.last_page}
                        </span>
                        <button
                            type="button"
                            disabled={!complaints.next_page_url}
                            onClick={() =>
                                complaints.next_page_url &&
                                router.visit(complaints.next_page_url, {
                                    preserveScroll: true,
                                })
                            }
                            className="rounded-md border border-gray-300 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                )}

                {selected && (
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                    Reviewing
                                </p>
                                <h2 className="mt-1 text-xl font-bold text-gray-900">
                                    {selected.reference}
                                </h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    {selected.complaintType} — submitted{' '}
                                    {selected.submittedDate}
                                </p>
                            </div>

                            <StatusBadge status={selected.status} />
                        </div>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Detail
                                label="Complainant"
                                value={selected.resident.name}
                            />
                            <Detail
                                label="Resident Number"
                                value={selected.resident.residentNumber || '—'}
                            />
                            <Detail
                                label="Respondent"
                                value={selected.respondentName}
                            />
                            <Detail
                                label="Respondent is a barangay resident"
                                value={selected.respondentResidentStatus}
                            />
                            <Detail
                                label="Respondent address"
                                value={
                                    selected.respondentAddress || 'Not provided'
                                }
                            />
                            <Detail
                                label="Respondent contact"
                                value={
                                    selected.respondentContact || 'Not provided'
                                }
                            />
                            <Detail
                                label="Incident date"
                                value={selected.incidentDate}
                            />
                            <Detail
                                label="Incident time"
                                value={selected.incidentTime || 'Not provided'}
                            />
                            <Detail
                                label="Incident location"
                                value={selected.incidentLocation}
                            />
                        </div>

                        <div className="mt-5">
                            <p className="text-sm font-bold text-gray-900">
                                Complaint narrative
                            </p>
                            <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-700">
                                {selected.narrative}
                            </p>
                        </div>

                        <div className="mt-5">
                            <p className="text-sm font-bold text-gray-900">
                                Requested action
                            </p>
                            <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-700">
                                {selected.requestedAction}
                            </p>
                        </div>

                        {selected.luponNote && (
                            <div className="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm font-bold text-gray-900">
                                    Note on this complaint
                                </p>
                                <p className="mt-2 text-sm leading-6 whitespace-pre-line text-gray-700">
                                    {selected.luponNote}
                                </p>
                            </div>
                        )}

                        <div className="mt-6 border-t border-gray-100 pt-5">
                            <EvidenceList files={selected.evidence ?? []} />
                            <p className="text-sm font-bold text-gray-900">
                                Review and payment
                            </p>

                            <div className="mt-3">
                                <label className="mb-1.5 block text-sm font-semibold text-gray-700">
                                    Note
                                    <span className="ml-1 text-xs font-normal text-gray-400">
                                        (Required when rejecting)
                                    </span>
                                </label>

                                <textarea
                                    rows={3}
                                    value={note}
                                    onChange={(event) =>
                                        setNote(event.target.value)
                                    }
                                    placeholder="Optional note, or the reason when rejecting..."
                                    className="min-h-11 w-full rounded-xl border border-gray-300 bg-white px-3.5 py-2.5 text-base text-gray-800 transition outline-none placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
                                />
                            </div>

                            {actionError && (
                                <p className="mt-2 text-sm text-red-600">
                                    {actionError}
                                </p>
                            )}

                            {!selected.case &&
                                [
                                    'Submitted',
                                    'Under Review',
                                    'Accepted',
                                ].includes(selected.status) && (
                                    <div className="mt-4 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                        <label className="block text-sm font-semibold text-gray-800">
                                            Assessed filing fee (₱)
                                            <input
                                                type="number"
                                                min="0.01"
                                                max="999999.99"
                                                step="0.01"
                                                value={assessedFee}
                                                onChange={(event) =>
                                                    setAssessedFee(
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-1 block w-full rounded-lg border bg-white p-2"
                                            />
                                        </label>
                                        <p className="text-xs text-gray-600">
                                            Complete the review and enter the
                                            assessed fee. The resident will be
                                            asked to pay at the barangay office.
                                            This does not create a case.
                                        </p>
                                        <ActionButton
                                            label={
                                                pendingAction
                                                    ? 'Saving review…'
                                                    : 'Review completed — request payment'
                                            }
                                            disabled={pendingAction}
                                            onClick={() =>
                                                requestPayment(selected)
                                            }
                                            tone="amber"
                                        />
                                    </div>
                                )}
                            {selected.status === 'For Payment' &&
                                !selected.case && (
                                    <div className="mt-4">
                                        <PromoteComplaint
                                            key={selected.id}
                                            complaintId={selected.id}
                                            officials={officials}
                                            assessedFee={
                                                selected.assessedFee ?? 0
                                            }
                                        />
                                    </div>
                                )}
                            <div className="mt-4 flex flex-wrap gap-3">
                                {!selected.case &&
                                    [
                                        'Submitted',
                                        'Under Review',
                                        'For Payment',
                                    ].includes(selected.status) && (
                                        <ActionButton
                                            label={
                                                pendingAction
                                                    ? 'Saving…'
                                                    : 'Reject complaint'
                                            }
                                            disabled={pendingAction}
                                            onClick={() =>
                                                submitStatus(
                                                    selected,
                                                    'Rejected',
                                                )
                                            }
                                            tone="red"
                                        />
                                    )}
                                {selected.case && (
                                    <a
                                        href={`/lupon/cases/${selected.case.id}`}
                                        className="inline-flex min-h-11 items-center rounded-xl border border-[#3f6f3d] bg-[#f0f6ee] px-5 py-2.5 text-sm font-semibold text-[#3f6f3d] hover:bg-[#e4efe1]"
                                    >
                                        View Case {selected.case.caseNumber} →
                                    </a>
                                )}
                            </div>
                            <p className="mt-3 text-xs leading-5 text-gray-400">
                                Statuses follow recorded review and payment
                                actions. Open the docketed case to manage
                                hearings, outcomes, evidence and documents.
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </LuponLayout>
    );
}

function ComplaintRow({
    complaint,
    expanded,
    onToggle,
}: {
    complaint: Complaint;
    expanded: boolean;
    onToggle: () => void;
}) {
    return (
        <div
            className={`rounded-2xl border bg-white p-4 shadow-sm transition sm:p-5 ${
                expanded
                    ? 'border-[#3f6f3d]'
                    : 'border-gray-200 hover:border-gray-300'
            }`}
        >
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-bold text-gray-900">
                            {complaint.reference}
                        </p>
                        <StatusBadge status={complaint.status} />
                    </div>

                    <p className="mt-2 text-sm font-semibold text-gray-800">
                        {complaint.complaintType}
                    </p>

                    <div className="mt-3 grid gap-2 text-sm text-gray-500 sm:grid-cols-2 sm:gap-x-8">
                        <p>
                            <span className="text-gray-400">Complainant:</span>{' '}
                            {complaint.resident.name}
                        </p>
                        <p>
                            <span className="text-gray-400">Respondent:</span>{' '}
                            {complaint.respondentName}
                        </p>
                        <p>
                            <span className="text-gray-400">Incident:</span>{' '}
                            {complaint.incidentDate}
                        </p>
                        <p>
                            <span className="text-gray-400">Submitted:</span>{' '}
                            {complaint.submittedDate}
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={onToggle}
                    className="min-h-10 shrink-0 rounded-xl border border-[#bcd2b6] bg-white px-4 py-2 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#f0f6ee]"
                >
                    {expanded ? 'Close' : 'Review'}
                </button>
            </div>
        </div>
    );
}

function FilterChip({
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
            className={`min-h-10 rounded-full border px-4 py-2 text-sm font-semibold transition ${
                active
                    ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                    : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
            }`}
        >
            {label}
        </button>
    );
}

function StatPill({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2">
            <p className="text-xs text-gray-500">{label}</p>
            <p className="text-lg font-semibold text-gray-800">{value}</p>
        </div>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-gray-400">{label}</p>
            <p className="mt-1 text-sm leading-5 font-medium break-words text-gray-700">
                {value}
            </p>
        </div>
    );
}

function StatusBadge({ status }: { status: ComplaintStatus }) {
    const styles: Record<ComplaintStatus, string> = {
        Submitted: 'bg-blue-50 text-blue-700 border-blue-200',
        'Under Review': 'bg-amber-50 text-amber-700 border-amber-200',
        'For Payment': 'bg-orange-50 text-orange-800 border-orange-200',
        Accepted: 'bg-green-50 text-green-700 border-green-200',
        Rejected: 'bg-red-50 text-red-700 border-red-200',
    };

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full border px-2.5 py-1 text-[11px] font-bold ${styles[status]}`}
        >
            {status}
        </span>
    );
}

function ActionButton({
    label,
    disabled,
    onClick,
    tone,
}: {
    label: string;
    disabled: boolean;
    onClick: () => void;
    tone: 'amber' | 'green' | 'red';
}) {
    const tones = {
        amber: 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100',
        green: 'border-green-300 bg-green-50 text-green-800 hover:bg-green-100',
        red: 'border-red-300 bg-red-50 text-red-800 hover:bg-red-100',
    } as const;

    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={`min-h-11 rounded-xl border px-5 py-2.5 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-40 ${tones[tone]}`}
        >
            {label}
        </button>
    );
}
