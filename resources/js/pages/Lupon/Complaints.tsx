import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';

type ComplaintStatus = 'Submitted' | 'Under Review' | 'Accepted' | 'Rejected';

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
    status: ComplaintStatus;
    luponNote?: string | null;
    submittedDate: string;
    case?: {
        id: number;
        caseNumber: string;
        phase: string;
        status: string;
    } | null;
};

type PageProps = {
    auth: { user: { name?: string; staff_id?: string; department?: string } };
    userName?: string;
    barangayName?: string | null;
    complaints: Complaint[];
    statuses: ComplaintStatus[];
    activeStatusFilter: ComplaintStatus | null;
    stats: {
        pendingCount: number;
        byStatus: Record<ComplaintStatus, number>;
    };
    flash?: { success?: string | null };
};

export default function LuponComplaints({
    auth,
    userName,
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

    const applyFilter = (status: ComplaintStatus | null) => {
        setSelected(null);

        router.get(
            status ? `/lupon/complaints?status=${encodeURIComponent(status)}` : '/lupon/complaints',
            {},
            { preserveState: true, preserveScroll: true },
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
                onFinish: () => {
                    setPendingAction(false);
                    setNote('');
                    setSelected(null);
                },
            },
        );
    };

    return (
        <LuponLayout active="complaints" user={auth.user} barangayName={barangayName}>
            <Head title="Lupon Complaints" />

            <div className="mx-auto max-w-7xl space-y-6 font-sans p-2 sm:p-4">
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h1 className="text-2xl font-semibold text-gray-800">Complaints</h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Review and update complaints submitted by residents
                        {userName ? ` — signed in as ${userName}` : ''}.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <StatPill label="Pending review" value={stats.pendingCount} />

                        {statuses.map((status) => (
                            <StatPill key={status} label={status} value={stats.byStatus[status] ?? 0} />
                        ))}
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-2xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                        {flash.success}
                    </div>
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

                {complaints.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-gray-300 bg-[#fafaf7] p-10 text-center">
                        <h2 className="text-base font-semibold text-gray-800">No complaints found</h2>
                        <p className="mx-auto mt-2 max-w-xl text-sm text-gray-500">
                            {activeStatusFilter
                                ? `There are no complaints with the status "${activeStatusFilter}" in this barangay.`
                                : 'No complaints have been submitted in this barangay yet.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {complaints.map((complaint) => (
                            <ComplaintRow
                                key={complaint.id}
                                complaint={complaint}
                                expanded={selected?.id === complaint.id}
                                onToggle={() => {
                                    setSelected(selected?.id === complaint.id ? null : complaint);
                                    setNote('');
                                    setActionError(null);
                                }}
                            />
                        ))}
                    </div>
                )}

                {selected && (
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Reviewing
                                </p>
                                <h2 className="mt-1 text-xl font-bold text-gray-900">{selected.reference}</h2>
                                <p className="mt-1 text-sm text-gray-500">
                                    {selected.complaintType} — submitted {selected.submittedDate}
                                </p>
                            </div>

                            <StatusBadge status={selected.status} />
                        </div>

                        <div className="mt-5 grid gap-4 sm:grid-cols-2">
                            <Detail label="Complainant" value={selected.resident.name} />
                            <Detail label="Resident Number" value={selected.resident.residentNumber || '—'} />
                            <Detail label="Respondent" value={selected.respondentName} />
                            <Detail
                                label="Respondent is a barangay resident"
                                value={selected.respondentResidentStatus}
                            />
                            <Detail label="Respondent address" value={selected.respondentAddress || 'Not provided'} />
                            <Detail label="Respondent contact" value={selected.respondentContact || 'Not provided'} />
                            <Detail label="Incident date" value={selected.incidentDate} />
                            <Detail label="Incident time" value={selected.incidentTime || 'Not provided'} />
                            <Detail label="Incident location" value={selected.incidentLocation} />
                        </div>

                        <div className="mt-5">
                            <p className="text-sm font-bold text-gray-900">Complaint narrative</p>
                            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700">
                                {selected.narrative}
                            </p>
                        </div>

                        <div className="mt-5">
                            <p className="text-sm font-bold text-gray-900">Requested action</p>
                            <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700">
                                {selected.requestedAction}
                            </p>
                        </div>

                        {selected.luponNote && (
                            <div className="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <p className="text-sm font-bold text-gray-900">Note on this complaint</p>
                                <p className="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700">
                                    {selected.luponNote}
                                </p>
                            </div>
                        )}

                        <div className="mt-6 border-t border-gray-100 pt-5">
                            <p className="text-sm font-bold text-gray-900">Update status</p>

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
                                    onChange={(event) => setNote(event.target.value)}
                                    placeholder="Optional note, or the reason when rejecting..."
                                    className="min-h-11 w-full rounded-xl border border-gray-300 bg-white px-3.5 py-2.5 text-base text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
                                />
                            </div>

                            {actionError && (
                                <p className="mt-2 text-sm text-red-600">{actionError}</p>
                            )}

                            <div className="mt-4 flex flex-wrap gap-3">
                                <ActionButton
                                    label="Mark Under Review"
                                    disabled={selected.status !== 'Submitted' || pendingAction}
                                    onClick={() => submitStatus(selected, 'Under Review')}
                                    tone="amber"
                                />

                                <ActionButton
                                    label="Accept"
                                    disabled={
                                        !['Submitted', 'Under Review'].includes(selected.status) ||
                                        selected.status === 'Accepted' ||
                                        pendingAction
                                    }
                                    onClick={() => submitStatus(selected, 'Accepted')}
                                    tone="green"
                                />

                                <ActionButton
                                    label="Reject"
                                    disabled={
                                        !['Submitted', 'Under Review'].includes(selected.status) ||
                                        selected.status === 'Rejected' ||
                                        pendingAction
                                    }
                                    onClick={() => submitStatus(selected, 'Rejected')}
                                    tone="red"
                                />

                                {selected.status === 'Accepted' && !selected.case && (
                                    <button
                                        type="button"
                                        disabled={pendingAction}
                                        onClick={() => {
                                            if (confirm(`Promote complaint ${selected.reference} into an active Lupon Case?`)) {
                                                setPendingAction(true);
                                                router.post(`/lupon/complaints/${selected.id}/promote`, {}, {
                                                    onFinish: () => setPendingAction(false),
                                                });
                                            }
                                        }}
                                        className="min-h-11 rounded-xl bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#315b30] disabled:opacity-40"
                                    >
                                        ⚖️ Promote to Case
                                    </button>
                                )}

                                {selected.case && (
                                    <a
                                        href={`/lupon/cases/${selected.case.id}`}
                                        className="min-h-11 inline-flex items-center rounded-xl border border-[#3f6f3d] bg-[#f0f6ee] px-5 py-2.5 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#e4efe1]"
                                    >
                                        📂 View Case {selected.case.caseNumber} →
                                    </a>
                                )}
                            </div>

                            <p className="mt-3 text-xs leading-5 text-gray-400">
                                Accepted and Rejected are final for intake. Hearing scheduling and case
                                management are not yet available.
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
                expanded ? 'border-[#3f6f3d]' : 'border-gray-200 hover:border-gray-300'
            }`}
        >
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-bold text-gray-900">{complaint.reference}</p>
                        <StatusBadge status={complaint.status} />
                    </div>

                    <p className="mt-2 text-sm font-semibold text-gray-800">{complaint.complaintType}</p>

                    <div className="mt-3 grid gap-2 text-sm text-gray-500 sm:grid-cols-2 sm:gap-x-8">
                        <p>
                            <span className="text-gray-400">Complainant:</span> {complaint.resident.name}
                        </p>
                        <p>
                            <span className="text-gray-400">Respondent:</span> {complaint.respondentName}
                        </p>
                        <p>
                            <span className="text-gray-400">Incident:</span> {complaint.incidentDate}
                        </p>
                        <p>
                            <span className="text-gray-400">Submitted:</span> {complaint.submittedDate}
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
            <p className="mt-1 break-words text-sm font-medium leading-5 text-gray-700">{value}</p>
        </div>
    );
}

function StatusBadge({ status }: { status: ComplaintStatus }) {
    const styles: Record<ComplaintStatus, string> = {
        Submitted: 'bg-blue-50 text-blue-700 border-blue-200',
        'Under Review': 'bg-amber-50 text-amber-700 border-amber-200',
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
