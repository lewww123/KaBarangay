import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';

type ResidentOption = {
    id: number;
    name: string;
    residentNumber: string;
};

type RespondentParty = {
    id: number;
    name: string;
    isResident: boolean;
    residentId?: number | null;
    residentName?: string | null;
    residentNumber?: string | null;
    associationStatus?: string | null;
    verifiedAt?: string | null;
    address?: string;
    contactNumber?: string;
    isPrimary: boolean;
};

type Props = {
    auth: { user: { name?: string; staff_id?: string; department?: string } };
    caseData: {
        id: number;
        caseNumber: string;
        origin?: string;
        title: string;
        caseType: string;
        phase: string;
        status: string;
        filedDate: string;
        promotedAt?: string;
        createdByName?: string;
        assignedStaffName?: string;
        complaint?: {
            id: number;
            reference: string;
            incidentDate?: string;
            incidentLocation?: string;
            narrative: string;
            requestedAction: string;
            luponNote?: string;
        } | null;
        complainants: Array<{
            id: number;
            name: string;
            isResident: boolean;
            residentNumber?: string;
            address?: string;
            contactNumber?: string;
            isPrimary: boolean;
        }>;
        respondents: RespondentParty[];
        hearings: Array<{
            id: number;
            hearingNumber: number;
            phase: string;
            date: string;
            time?: string;
            venue: string;
            status: string;
            outcome?: string;
            notes?: string;
            officerName?: string;
        }>;
        settlement?: {
            id: number;
            settlementType: string;
            settlementDate: string;
            terms: string;
            status: string;
            recordedByName?: string;
        } | null;
    };
    barangayResidents?: ResidentOption[];
    availablePhases: string[];
    availableStatuses: string[];
};

export default function CaseShow({
    auth,
    caseData,
    barangayResidents = [],
    availablePhases,
    availableStatuses,
}: Props) {
    const [phase, setPhase] = useState(caseData.phase);
    const [status, setStatus] = useState(caseData.status);

    // Modals
    const [showHearingModal, setShowHearingModal] = useState(false);
    const [hearingDate, setHearingDate] = useState('');
    const [hearingTime, setHearingTime] = useState('');
    const [venue, setVenue] = useState('Barangay Hall');
    const [hearingPhase, setHearingPhase] = useState(caseData.phase);

    const [showPartyModal, setShowPartyModal] = useState(false);
    const [partyName, setPartyName] = useState('');
    const [partyAddress, setPartyAddress] = useState('');
    const [partyContact, setPartyContact] = useState('');

    const [showSettlementModal, setShowSettlementModal] = useState(false);
    const [settlementType, setSettlementType] = useState('Amicable Settlement');
    const [settlementDate, setSettlementDate] = useState(new Date().toISOString().split('T')[0]);
    const [settlementTerms, setSettlementTerms] = useState('');

    // Associate Respondent Modal
    const [showAssociateModal, setShowAssociateModal] = useState(false);
    const [selectedParty, setSelectedParty] = useState<RespondentParty | null>(null);
    const [associationAction, setAssociationAction] = useState<'link_verify' | 'unlink'>('link_verify');
    const [selectedResidentId, setSelectedResidentId] = useState<number | ''>('');
    const [verificationNotes, setVerificationNotes] = useState('');
    const [associationLoading, setAssociationLoading] = useState(false);

    const handleUpdateStatus = () => {
        router.patch(`/lupon/cases/${caseData.id}/status`, {
            phase,
            status,
        }, { preserveScroll: true });
    };

    const handleScheduleHearing = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/lupon/cases/${caseData.id}/hearings`, {
            hearing_date: hearingDate,
            hearing_time: hearingTime,
            venue,
            phase: hearingPhase,
        }, {
            onSuccess: () => setShowHearingModal(false),
            preserveScroll: true,
        });
    };

    const handleAddComplainant = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/lupon/cases/${caseData.id}/complainants`, {
            name: partyName,
            address: partyAddress,
            contact_number: partyContact,
        }, {
            onSuccess: () => {
                setShowPartyModal(false);
                setPartyName('');
                setPartyAddress('');
                setPartyContact('');
            },
            preserveScroll: true,
        });
    };

    const handleRecordSettlement = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/lupon/cases/${caseData.id}/settlements`, {
            settlement_type: settlementType,
            settlement_date: settlementDate,
            terms: settlementTerms,
        }, {
            onSuccess: () => setShowSettlementModal(false),
            preserveScroll: true,
        });
    };

    const openAssociateModal = (party: RespondentParty, action: 'link_verify' | 'unlink') => {
        setSelectedParty(party);
        setAssociationAction(action);
        setSelectedResidentId(party.residentId ?? '');
        setVerificationNotes('');
        setShowAssociateModal(true);
    };

    const handleAssociateSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedParty) return;

        setAssociationLoading(true);
        router.post(
            `/lupon/parties/${selectedParty.id}/associate-respondent`,
            {
                resident_id: associationAction === 'link_verify' ? selectedResidentId : null,
                action: associationAction,
                verification_notes: verificationNotes || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setShowAssociateModal(false);
                    setSelectedParty(null);
                    setAssociationLoading(false);
                },
                onError: () => {
                    setAssociationLoading(false);
                },
            }
        );
    };

    return (
        <LuponLayout active="cases" user={auth.user}>
            <Head title={`Case ${caseData.caseNumber}`} />

            <div className="mx-auto max-w-7xl space-y-6 font-sans p-2 sm:p-4">
                {/* Navigation and Top Card */}
                <div className="flex items-center justify-between">
                    <Link
                        href="/lupon/cases"
                        className="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-600 hover:text-gray-900"
                    >
                        ← Back to Cases
                    </Link>

                    {caseData.complaint ? (
                        <span className="text-xs text-gray-500">
                            Originating Complaint: <span className="font-semibold text-gray-800">{caseData.complaint.reference}</span>
                        </span>
                    ) : (
                        <span className="rounded-full bg-amber-50 border border-amber-200 px-3 py-1 text-xs font-semibold text-amber-800">
                            Direct Walk-in Case (Manual Encoding)
                        </span>
                    )}
                </div>

                {/* Case Banner */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-bold text-gray-900">{caseData.caseNumber}</h1>
                                <span className="rounded-full bg-[#e8f1e4] px-3 py-0.5 text-xs font-semibold text-[#3f6f3d]">
                                    {caseData.phase} Phase
                                </span>
                                <span className="rounded-full bg-blue-50 border border-blue-200 px-3 py-0.5 text-xs font-semibold text-blue-700">
                                    {caseData.status}
                                </span>
                                {caseData.origin === 'manual' && (
                                    <span className="rounded-full bg-purple-50 border border-purple-200 px-2.5 py-0.5 text-xs font-semibold text-purple-700">
                                        Walk-in
                                    </span>
                                )}
                            </div>

                            <p className="text-base font-semibold text-gray-800">{caseData.title}</p>
                            <p className="text-xs text-gray-500">
                                Filed on {caseData.filedDate}
                                {caseData.promotedAt && ` • Promoted on ${caseData.promotedAt}`}
                                {caseData.createdByName && ` • Encoded by ${caseData.createdByName}`}
                            </p>
                        </div>

                        {/* Status Controls */}
                        <div className="flex flex-wrap items-center gap-2 rounded-xl bg-gray-50 p-3 border border-gray-200">
                            <select
                                value={phase}
                                onChange={(e) => setPhase(e.target.value)}
                                className="rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-800"
                            >
                                {availablePhases.map((p) => (
                                    <option key={p} value={p}>{p} Phase</option>
                                ))}
                            </select>

                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-800"
                            >
                                {availableStatuses.map((s) => (
                                    <option key={s} value={s}>{s}</option>
                                ))}
                            </select>

                            <button
                                type="button"
                                onClick={handleUpdateStatus}
                                className="rounded-lg bg-[#3f6f3d] px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30]"
                            >
                                Update Status
                            </button>
                        </div>
                    </div>
                </div>

                {/* Primary Content Grid */}
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Left 2 Cols: Parties, Hearings, Settlement */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Parties Card */}
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-4">
                                <h2 className="text-base font-bold text-gray-900">Case Parties</h2>
                                <button
                                    type="button"
                                    onClick={() => setShowPartyModal(true)}
                                    className="rounded-lg border border-[#3f6f3d] px-3 py-1 text-xs font-semibold text-[#3f6f3d] hover:bg-[#f0f6ee]"
                                >
                                    + Add Complainant
                                </button>
                            </div>

                            <div className="mt-4 grid gap-6 sm:grid-cols-2">
                                <div>
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-gray-400">Complainants</h3>
                                    <div className="mt-3 space-y-3">
                                        {caseData.complainants.map((c) => (
                                            <div key={c.id} className="rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-gray-900">{c.name}</span>
                                                    {c.isPrimary && (
                                                        <span className="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold text-gray-700">Primary</span>
                                                    )}
                                                </div>
                                                <p className="mt-1 text-xs text-gray-500">{c.address || 'Address not provided'}</p>
                                                {c.contactNumber && <p className="text-xs text-gray-500">📞 {c.contactNumber}</p>}
                                                {c.residentNumber && (
                                                    <p className="mt-1 text-[11px] font-medium text-[#3f6f3d]">Resident ID: {c.residentNumber}</p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-gray-400">Respondents</h3>
                                    <div className="mt-3 space-y-3">
                                        {caseData.respondents.map((r) => (
                                            <div key={r.id} className="rounded-xl border border-gray-200 bg-gray-50/70 p-3.5 text-sm space-y-2">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-gray-900">{r.name}</span>
                                                    {r.isPrimary && (
                                                        <span className="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold text-gray-700">Primary</span>
                                                    )}
                                                </div>
                                                <p className="text-xs text-gray-500">{r.address || 'Address not provided'}</p>
                                                {r.contactNumber && <p className="text-xs text-gray-500">📞 {r.contactNumber}</p>}

                                                {/* Account Association Section */}
                                                <div className="mt-2.5 rounded-lg border border-gray-200 bg-white p-2.5 space-y-2">
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-[11px] font-semibold text-gray-500">Portal Account:</span>
                                                        {r.associationStatus === 'verified' ? (
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-green-50 border border-green-200 px-2 py-0.5 text-[10px] font-bold text-green-700">
                                                                ✓ Staff-Verified
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600">
                                                                Unlinked / Offline
                                                            </span>
                                                        )}
                                                    </div>

                                                    {r.associationStatus === 'verified' && r.residentName && (
                                                        <div className="text-xs text-gray-700">
                                                            <p className="font-medium">Linked: {r.residentName}</p>
                                                            {r.residentNumber && <p className="text-[11px] text-gray-500">ID: {r.residentNumber}</p>}
                                                            {r.verifiedAt && <p className="text-[10px] text-gray-400">Verified: {r.verifiedAt}</p>}
                                                        </div>
                                                    )}

                                                    <div className="flex gap-2 pt-1 border-t border-gray-100">
                                                        {r.associationStatus === 'verified' ? (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openAssociateModal(r, 'link_verify')}
                                                                    className="text-xs font-semibold text-[#3f6f3d] hover:underline"
                                                                >
                                                                    Change Account
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openAssociateModal(r, 'unlink')}
                                                                    className="text-xs font-semibold text-red-600 hover:underline"
                                                                >
                                                                    Unlink
                                                                </button>
                                                            </>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() => openAssociateModal(r, 'link_verify')}
                                                                className="rounded-lg bg-[#3f6f3d] px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30]"
                                                            >
                                                                Link & Verify Resident Account
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Hearings Card */}
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-4">
                                <h2 className="text-base font-bold text-gray-900">Hearings & Mediation Proceedings</h2>
                                <button
                                    type="button"
                                    onClick={() => setShowHearingModal(true)}
                                    className="rounded-xl bg-[#3f6f3d] px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30]"
                                >
                                    + Schedule Hearing
                                </button>
                            </div>

                            <div className="mt-4">
                                {caseData.hearings.length === 0 ? (
                                    <div className="p-6 text-center text-sm text-gray-500">
                                        No hearings have been scheduled yet for this case.
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        {caseData.hearings.map((h) => (
                                            <div key={h.id} className="rounded-xl border border-gray-200 p-4">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <span className="font-bold text-gray-900">Hearing #{h.hearingNumber}</span>
                                                        <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                                                            {h.phase} Phase
                                                        </span>
                                                    </div>
                                                    <span className="rounded-full bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                                                        {h.status}
                                                    </span>
                                                </div>

                                                <div className="mt-2 text-xs text-gray-600 space-y-1">
                                                    <p>📅 <strong>Date:</strong> {h.date} {h.time && `at ${h.time}`}</p>
                                                    <p>📍 <strong>Venue:</strong> {h.venue}</p>
                                                    {h.outcome && <p>⚖️ <strong>Outcome:</strong> {h.outcome}</p>}
                                                    {h.notes && <p className="mt-2 text-gray-700 italic border-l-2 border-gray-300 pl-2">{h.notes}</p>}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Settlement Section */}
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-4">
                                <h2 className="text-base font-bold text-gray-900">Settlement & Resolution</h2>
                                {!caseData.settlement && (
                                    <button
                                        type="button"
                                        onClick={() => setShowSettlementModal(true)}
                                        className="rounded-xl bg-[#3f6f3d] px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30]"
                                    >
                                        Record Settlement
                                    </button>
                                )}
                            </div>

                            <div className="mt-4">
                                {caseData.settlement ? (
                                    <div className="rounded-xl border border-green-200 bg-green-50/50 p-4">
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-green-900">{caseData.settlement.settlementType}</span>
                                            <span className="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-800">
                                                {caseData.settlement.status}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-green-700">Date Recorded: {caseData.settlement.settlementDate}</p>
                                        <div className="mt-3 rounded-lg bg-white p-3 border border-green-100 text-xs text-gray-800 whitespace-pre-line">
                                            {caseData.settlement.terms}
                                        </div>
                                    </div>
                                ) : (
                                    <p className="p-4 text-center text-sm text-gray-500">
                                        No amicable settlement or resolution has been officially recorded.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Right Col: Originating Complaint or Manual Case Information */}
                    <div className="space-y-6">
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <h2 className="text-base font-bold text-gray-900 border-b border-gray-100 pb-3">
                                {caseData.complaint ? 'Originating Complaint Details' : 'Manual Walk-in Details'}
                            </h2>
                            <div className="mt-4 space-y-3 text-xs leading-5 text-gray-600">
                                <div>
                                    <span className="font-semibold text-gray-400 uppercase">Nature</span>
                                    <p className="font-medium text-gray-900 text-sm">{caseData.caseType}</p>
                                </div>

                                {caseData.complaint ? (
                                    <>
                                        <div>
                                            <span className="font-semibold text-gray-400 uppercase">Incident Location</span>
                                            <p className="text-gray-800">{caseData.complaint.incidentLocation || 'N/A'}</p>
                                        </div>

                                        <div>
                                            <span className="font-semibold text-gray-400 uppercase">Incident Date</span>
                                            <p className="text-gray-800">{caseData.complaint.incidentDate || 'N/A'}</p>
                                        </div>

                                        <div>
                                            <span className="font-semibold text-gray-400 uppercase">Complainant Narrative</span>
                                            <p className="mt-1 rounded-lg bg-gray-50 p-2.5 text-gray-800 whitespace-pre-line border border-gray-200">
                                                {caseData.complaint.narrative}
                                            </p>
                                        </div>

                                        <div>
                                            <span className="font-semibold text-gray-400 uppercase">Requested Action</span>
                                            <p className="mt-1 rounded-lg bg-gray-50 p-2.5 text-gray-800 whitespace-pre-line border border-gray-200">
                                                {caseData.complaint.requestedAction}
                                            </p>
                                        </div>

                                        {caseData.complaint.luponNote && (
                                            <div>
                                                <span className="font-semibold text-gray-400 uppercase">Intake Review Note</span>
                                                <p className="mt-1 rounded-lg bg-amber-50 p-2.5 text-amber-900 border border-amber-200">
                                                    {caseData.complaint.luponNote}
                                                </p>
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <>
                                        <div>
                                            <span className="font-semibold text-gray-400 uppercase">Registration Type</span>
                                            <p className="text-gray-800 font-medium">Walk-in Direct Entry (Manual Case)</p>
                                        </div>
                                        <div>
                                            <span className="font-semibold text-gray-400 uppercase">Filed Date</span>
                                            <p className="text-gray-800">{caseData.filedDate}</p>
                                        </div>
                                        {caseData.createdByName && (
                                            <div>
                                                <span className="font-semibold text-gray-400 uppercase">Encoded By</span>
                                                <p className="text-gray-800">{caseData.createdByName}</p>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Modal: Schedule Hearing */}
            {showHearingModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-bold text-gray-900">Schedule Case Hearing</h3>
                        <form onSubmit={handleScheduleHearing} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Hearing Date</label>
                                <input
                                    type="date"
                                    required
                                    value={hearingDate}
                                    onChange={(e) => setHearingDate(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Time</label>
                                <input
                                    type="time"
                                    value={hearingTime}
                                    onChange={(e) => setHearingTime(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Venue</label>
                                <input
                                    type="text"
                                    value={venue}
                                    onChange={(e) => setVenue(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Proceedings Phase</label>
                                <select
                                    value={hearingPhase}
                                    onChange={(e) => setHearingPhase(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                >
                                    <option value="Kapitan">Kapitan Mediation Phase</option>
                                    <option value="Lupon">Lupon Conciliation Phase</option>
                                </select>
                            </div>

                            <div className="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowHearingModal(false)}
                                    className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                                >
                                    Schedule
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Add Complainant */}
            {showPartyModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-bold text-gray-900">Add Complainant</h3>
                        <form onSubmit={handleAddComplainant} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Full Name</label>
                                <input
                                    type="text"
                                    required
                                    value={partyName}
                                    onChange={(e) => setPartyName(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Address</label>
                                <input
                                    type="text"
                                    value={partyAddress}
                                    onChange={(e) => setPartyAddress(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Contact Number</label>
                                <input
                                    type="text"
                                    value={partyContact}
                                    onChange={(e) => setPartyContact(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowPartyModal(false)}
                                    className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                                >
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Record Settlement */}
            {showSettlementModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-bold text-gray-900">Record Settlement Agreement</h3>
                        <form onSubmit={handleRecordSettlement} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Settlement Type</label>
                                <select
                                    value={settlementType}
                                    onChange={(e) => setSettlementType(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                >
                                    <option value="Amicable Settlement">Amicable Settlement</option>
                                    <option value="Arbitration Award">Arbitration Award</option>
                                    <option value="Resolution">Resolution</option>
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Date of Agreement</label>
                                <input
                                    type="date"
                                    required
                                    value={settlementDate}
                                    onChange={(e) => setSettlementDate(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Agreed Settlement Terms</label>
                                <textarea
                                    required
                                    rows={4}
                                    value={settlementTerms}
                                    onChange={(e) => setSettlementTerms(e.target.value)}
                                    placeholder="Enter stipulations, agreements, or obligations decided upon by the parties..."
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowSettlementModal(false)}
                                    className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                                >
                                    Record Agreement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Associate / Unlink Respondent Resident Account */}
            {showAssociateModal && selectedParty && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-bold text-gray-900">
                            {associationAction === 'link_verify'
                                ? 'Verify & Link Resident Account'
                                : 'Unlink Resident Account'}
                        </h3>
                        <p className="mt-1 text-xs text-gray-500">
                            Respondent: <span className="font-semibold text-gray-800">{selectedParty.name}</span>
                        </p>

                        <form onSubmit={handleAssociateSubmit} className="mt-4 space-y-4">
                            {associationAction === 'link_verify' ? (
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Select Verified Barangay Resident <span className="text-red-500">*</span>
                                    </label>
                                    <select
                                        required
                                        value={selectedResidentId}
                                        onChange={(e) => setSelectedResidentId(Number(e.target.value))}
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm text-gray-800"
                                    >
                                        <option value="">-- Choose Resident --</option>
                                        {barangayResidents.map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.name} ({r.residentNumber})
                                            </option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-[11px] text-gray-500">
                                        Once verified, this case will be visible in the resident's "Complaints Against Me" portal tab.
                                    </p>
                                </div>
                            ) : (
                                <div className="rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
                                    Are you sure you want to unlink the resident account from this respondent party?
                                    The respondent will no longer see this case in their portal.
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Verification Notes</label>
                                <textarea
                                    rows={2}
                                    value={verificationNotes}
                                    onChange={(e) => setVerificationNotes(e.target.value)}
                                    placeholder={
                                        associationAction === 'link_verify'
                                            ? 'e.g. Identity verified via Barangay ID / physical appearance'
                                            : 'Reason for unlinking'
                                    }
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-xs text-gray-800"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-2">
                                <button
                                    type="button"
                                    disabled={associationLoading}
                                    onClick={() => setShowAssociateModal(false)}
                                    className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={associationLoading}
                                    className={`rounded-xl px-4 py-2 text-sm font-semibold text-white shadow-sm ${
                                        associationAction === 'link_verify'
                                            ? 'bg-[#3f6f3d] hover:bg-[#315b30]'
                                            : 'bg-red-600 hover:bg-red-700'
                                    }`}
                                >
                                    {associationLoading
                                        ? 'Saving...'
                                        : associationAction === 'link_verify'
                                        ? 'Verify & Associate'
                                        : 'Confirm Unlink'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </LuponLayout>
    );
}
