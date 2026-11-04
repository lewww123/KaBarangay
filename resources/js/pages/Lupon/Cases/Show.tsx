import {
    caseSaveOptions,
    CaseSaveFeedback,
    useCaseSaveStatus,
} from '@/components/lupon/CaseSaveFeedback';
import PangkatFormation from '@/components/lupon/PangkatFormation';
import SettlementDocument from '@/components/lupon/SettlementDocument';
import { createPortal } from 'react-dom';
import CaseOverview from '@/components/lupon/CaseOverview';
import CaseWorkspace from '@/components/lupon/CaseWorkspace';
import { Errors } from '@/components/lupon/WorkflowPanel';
import CaseTimeline from '@/components/lupon/CaseTimeline';
import WorkflowPanel from '@/components/lupon/WorkflowPanel';
import DocumentPanel from '@/components/lupon/DocumentPanel';
import EvidencePanel from '@/components/lupon/EvidencePanel';
import HearingRecords from '@/components/lupon/HearingRecords';
import ResidentAutocomplete from '@/components/certificates/ResidentAutocomplete';
import {
    luponStatusLabel,
    type LuponStatus,
    type LuponWorkflow,
    type LuponDocument,
    type LuponEvidenceFile,
    type LuponOfficials,
} from '@/components/lupon/types';
import { Head, Link, router, usePoll, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

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
    auth: {
        user: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
    caseData: {
        id: number;
        caseNumber: string;
        origin?: string;
        title: string;
        caseType: string;
        phase: string;
        status: LuponStatus;
        luponMembers?: string[];
        hearingActionsUnlocked?: boolean;
        workflow: LuponWorkflow;
        documents: LuponDocument[];
        evidence: LuponEvidenceFile[];
        paymentStatus: string;
        actingCaptainId?: number | null;
        intake: {
            narrative?: string | null;
            requestedAction?: string | null;
            incidentDate?: string | null;
            incidentLocation?: string | null;
        };
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
    officials: LuponOfficials;
};

export default withDeferredPage(CaseShow, ['caseData'], (props) => (
    <LuponLayout active="cases" user={props.auth.user}>
        <PortalPageSkeleton list />
    </LuponLayout>
));

function CaseShow({ auth, caseData, officials }: Props) {
    const { notice, busy: saving, dismiss } = useCaseSaveStatus();
    const { start: startPolling, stop: stopPolling } = usePoll(
        15000,
        { only: ['caseData'] },
        { autoStart: false },
    );
    useEffect(() => {
        if (saving) stopPolling();
        else startPolling();
    }, [saving, startPolling, stopPolling]);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [residentSearch, setResidentSearch] = useState('');

    // Modals
    const [showHearingModal, setShowHearingModal] = useState(false);
    const [hearingDate, setHearingDate] = useState('');
    const [hearingTime, setHearingTime] = useState('');
    const [venue, setVenue] = useState('Barangay Hall');
    const [hearingPhase, setHearingPhase] = useState(caseData.phase);
    const [previousHearingId, setPreviousHearingId] = useState<number | null>(
        null,
    );
    const [previousHearingNotes, setPreviousHearingNotes] = useState('');
    const phaseFirstDate = caseData.hearings
        .filter((h) => h.phase === caseData.phase)
        .sort((a, b) => a.hearingNumber - b.hearingNumber)[0]?.date;
    const phaseStart =
        caseData.phase === 'Kapitan'
            ? caseData.workflow.milestones.mediation_started_at
            : caseData.workflow.milestones.conciliation_started_at;
    const todayInManila = new Date().toLocaleDateString('en-CA', {
        timeZone: 'Asia/Manila',
    });
    const firstDateISO = phaseFirstDate
        ? new Date(phaseFirstDate).toLocaleDateString('en-CA', {
              timeZone: 'Asia/Manila',
          })
        : undefined;
    const windowStart =
        phaseStart ??
        (firstDateISO &&
        caseData.hearingActionsUnlocked !== false &&
        firstDateISO > todayInManila
            ? todayInManila
            : firstDateISO);
    const fallbackDeadline = windowStart
        ? new Date(Date.parse(windowStart + 'T00:00:00Z') + 15 * 86400000)
              .toISOString()
              .slice(0, 10)
        : undefined;
    const hearingDeadline =
        caseData.workflow.deadlines.find(
            (d) =>
                d.active &&
                d.label.includes(
                    caseData.phase === 'Kapitan' ? 'Mediation' : 'Conciliation',
                ),
        )?.date ?? fallbackDeadline;
    useEffect(() => setHearingPhase(caseData.phase), [caseData.phase]);

    const [showPartyModal, setShowPartyModal] = useState(false);
    const [partyName, setPartyName] = useState('');
    const [partyAddress, setPartyAddress] = useState('');
    const [partyContact, setPartyContact] = useState('');

    const [showSettlementModal, setShowSettlementModal] = useState(false);
    const [settlementType, setSettlementType] = useState('Amicable Settlement');
    const [settlementDate, setSettlementDate] = useState(
        new Date().toISOString().split('T')[0],
    );
    const [settlementTerms, setSettlementTerms] = useState('');

    // Associate Respondent Modal
    const [showAssociateModal, setShowAssociateModal] = useState(false);
    const [selectedParty, setSelectedParty] = useState<RespondentParty | null>(
        null,
    );
    const [associationAction, setAssociationAction] = useState<
        'link_verify' | 'unlink'
    >('link_verify');
    const [selectedResidentId, setSelectedResidentId] = useState<number | ''>(
        '',
    );
    const [verificationNotes, setVerificationNotes] = useState('');
    const [associationLoading, setAssociationLoading] = useState(false);

    const handleScheduleHearing = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            `/lupon/cases/${caseData.id}/hearings`,
            {
                hearing_date: hearingDate,
                hearing_time: hearingTime,
                venue,
                phase: hearingPhase,
                previous_hearing_id: previousHearingId,
                previous_hearing_notes: previousHearingNotes,
            },
            {
                onSuccess: () => setShowHearingModal(false),
                ...caseSaveOptions,
            },
        );
    };

    const handleAddComplainant = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            `/lupon/cases/${caseData.id}/complainants`,
            {
                name: partyName,
                address: partyAddress,
                contact_number: partyContact,
            },
            {
                onSuccess: () => {
                    setShowPartyModal(false);
                    setPartyName('');
                    setPartyAddress('');
                    setPartyContact('');
                },
                ...caseSaveOptions,
            },
        );
    };

    const handleRecordSettlement = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            `/lupon/cases/${caseData.id}/settlements`,
            {
                settlement_type: settlementType,
                settlement_date: settlementDate,
                terms: settlementTerms,
            },
            {
                onSuccess: () => {
                    setShowSettlementModal(true);
                },
                ...caseSaveOptions,
            },
        );
    };

    const openAssociateModal = (
        party: RespondentParty,
        action: 'link_verify' | 'unlink',
    ) => {
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
                resident_id:
                    associationAction === 'link_verify'
                        ? selectedResidentId
                        : null,
                action: associationAction,
                verification_notes: verificationNotes || undefined,
            },
            {
                ...caseSaveOptions,
                onSuccess: () => {
                    setShowAssociateModal(false);
                    setSelectedParty(null);
                    setAssociationLoading(false);
                },
                onError: () => {
                    setAssociationLoading(false);
                },
            },
        );
    };

    return (
        <LuponLayout active="cases" user={auth.user}>
            <Head title={`Case ${caseData.caseNumber}`} />
            <CaseSaveFeedback notice={notice} dismiss={dismiss} />

            <div className="mx-auto max-w-7xl space-y-6 p-2 font-sans sm:p-4">
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
                            Originating Complaint:{' '}
                            <span className="font-semibold text-gray-800">
                                {caseData.complaint.reference}
                            </span>
                        </span>
                    ) : (
                        <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800">
                            Direct Walk-in Case (Manual Encoding)
                        </span>
                    )}
                </div>

                {/* Case Banner */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-bold text-gray-900">
                                    {caseData.caseNumber}
                                </h1>
                                <span className="rounded-full bg-[#e8f1e4] px-3 py-0.5 text-xs font-semibold text-[#3f6f3d]">
                                    {caseData.phase} Phase
                                </span>
                                <span className="rounded-full border border-blue-200 bg-blue-50 px-3 py-0.5 text-xs font-semibold text-blue-700">
                                    {luponStatusLabel(caseData.status)}
                                </span>
                                {caseData.origin === 'manual' && (
                                    <span className="rounded-full border border-purple-200 bg-purple-50 px-2.5 py-0.5 text-xs font-semibold text-purple-700">
                                        Walk-in
                                    </span>
                                )}
                            </div>

                            <p className="text-base font-semibold text-gray-800">
                                {caseData.title}
                            </p>
                            <p className="text-xs text-gray-500">
                                Filed on {caseData.filedDate}
                                {caseData.assignedStaffName &&
                                    ` • Assigned to ${caseData.assignedStaffName}`}
                                {caseData.promotedAt &&
                                    ` • Promoted on ${caseData.promotedAt}`}
                                {caseData.createdByName &&
                                    ` • Encoded by ${caseData.createdByName}`}
                            </p>
                        </div>
                    </div>
                </div>

                <CaseWorkspace
                    overview={<CaseOverview caseData={caseData} />}
                    key={caseData.id}
                    workflow={caseData.workflow}
                    hearings={caseData.hearings}
                    documents={caseData.documents}
                    hasSettlement={Boolean(caseData.settlement)}
                    onSchedule={() => setShowHearingModal(true)}
                    panels={{
                        hearings: (
                            <>
                                <p className="text-sm text-slate-600">
                                    Dito magtakda ng meeting at mag-update ng
                                    hearing record pagkatapos ng aktuwal na
                                    pagdinig.
                                </p>{' '}
                                <section className="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h2 className="font-bold">
                                            Mga pagdinig
                                        </h2>
                                    </div>
                                    <HearingRecords
                                        hearings={caseData.hearings}
                                        currentPhase={caseData.phase}
                                        actionsUnlocked={
                                            caseData.hearingActionsUnlocked
                                        }
                                        pangkat={caseData.workflow.milestones}
                                        maxDate={
                                            caseData.workflow.deadlines.find(
                                                (d) =>
                                                    d.active &&
                                                    d.label.includes(
                                                        caseData.phase ===
                                                            'Kapitan'
                                                            ? 'Mediation'
                                                            : 'Conciliation',
                                                    ),
                                            )?.date
                                        }
                                        onSettle={() =>
                                            setShowSettlementModal(true)
                                        }
                                        onNext={(hearing, notes) => {
                                            setPreviousHearingId(
                                                hearing?.id ?? null,
                                            );
                                            setPreviousHearingNotes(
                                                notes ?? '',
                                            );
                                            setVenue(
                                                hearing?.venue ??
                                                    'Barangay Hall',
                                            );
                                            setHearingPhase(caseData.phase);
                                            setHearingDate('');
                                            setHearingTime('');
                                            setShowHearingModal(true);
                                        }}
                                        documents={caseData.documents}
                                        hasSettlement={Boolean(
                                            caseData.settlement,
                                        )}
                                    />
                                </section>
                            </>
                        ),
                        documents: (
                            <>
                                <p className="text-sm text-slate-600">
                                    Unahin ang fee o waiver kung kailangan, saka
                                    piliin ang dokumento at kaukulang pagdinig.
                                    Itala ang pag-abot kapag aktuwal nang
                                    naibigay ang notice.
                                </p>
                                <DocumentPanel
                                    caseId={caseData.id}
                                    status={caseData.status}
                                    paymentStatus={caseData.paymentStatus}
                                    actingCaptainId={caseData.actingCaptainId}
                                    documents={caseData.documents}
                                    officials={officials}
                                    hearings={caseData.hearings}
                                    hasSettlement={Boolean(caseData.settlement)}
                                    onSchedule={() => setShowHearingModal(true)}
                                />
                            </>
                        ),
                        resolution:
                            caseData.phase === 'Kapitan' ? (
                                <PangkatFormation
                                    caseId={caseData.id}
                                    workflow={caseData.workflow}
                                    candidates={caseData.luponMembers ?? []}
                                />
                            ) : (
                                <WorkflowPanel
                                    caseId={caseData.id}
                                    workflow={caseData.workflow}
                                />
                            ),
                        details: (
                            <>
                                {' '}
                                {/* Parties Card */}
                                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                                    <div className="flex items-center justify-between border-b border-gray-100 pb-4">
                                        <h2 className="text-base font-bold text-gray-900">
                                            Mga partido sa kaso
                                        </h2>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setShowPartyModal(true)
                                            }
                                            className="rounded-lg border border-[#3f6f3d] px-3 py-1 text-xs font-semibold text-[#3f6f3d] hover:bg-[#f0f6ee]"
                                        >
                                            + Add Complainant
                                        </button>
                                    </div>

                                    <div className="mt-4 grid gap-6 sm:grid-cols-2">
                                        <div>
                                            <h3 className="text-xs font-bold tracking-wider text-gray-400 uppercase">
                                                Complainants
                                            </h3>
                                            <div className="mt-3 space-y-3">
                                                {caseData.complainants.map(
                                                    (c) => (
                                                        <div
                                                            key={c.id}
                                                            className="rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm"
                                                        >
                                                            <div className="flex items-center justify-between">
                                                                <span className="font-semibold text-gray-900">
                                                                    {c.name}
                                                                </span>
                                                                {c.isPrimary && (
                                                                    <span className="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold text-gray-700">
                                                                        Primary
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <p className="mt-1 text-xs text-gray-500">
                                                                {c.address ||
                                                                    'Address not provided'}
                                                            </p>
                                                            {c.contactNumber && (
                                                                <p className="text-xs text-gray-500">
                                                                    📞{' '}
                                                                    {
                                                                        c.contactNumber
                                                                    }
                                                                </p>
                                                            )}
                                                            {c.residentNumber && (
                                                                <p className="mt-1 text-[11px] font-medium text-[#3f6f3d]">
                                                                    Resident ID:{' '}
                                                                    {
                                                                        c.residentNumber
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>

                                        <div>
                                            <h3 className="text-xs font-bold tracking-wider text-gray-400 uppercase">
                                                Respondents
                                            </h3>
                                            <div className="mt-3 space-y-3">
                                                {caseData.respondents.map(
                                                    (r) => (
                                                        <div
                                                            key={r.id}
                                                            className="space-y-2 rounded-xl border border-gray-200 bg-gray-50/70 p-3.5 text-sm"
                                                        >
                                                            <div className="flex items-center justify-between">
                                                                <span className="font-semibold text-gray-900">
                                                                    {r.name}
                                                                </span>
                                                                {r.isPrimary && (
                                                                    <span className="rounded bg-gray-200 px-1.5 py-0.5 text-[10px] font-bold text-gray-700">
                                                                        Primary
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <p className="text-xs text-gray-500">
                                                                {r.address ||
                                                                    'Address not provided'}
                                                            </p>
                                                            {r.contactNumber && (
                                                                <p className="text-xs text-gray-500">
                                                                    📞{' '}
                                                                    {
                                                                        r.contactNumber
                                                                    }
                                                                </p>
                                                            )}

                                                            {/* Account Association Section */}
                                                            <div className="mt-2.5 space-y-2 rounded-lg border border-gray-200 bg-white p-2.5">
                                                                <div className="flex items-center justify-between">
                                                                    <span className="text-[11px] font-semibold text-gray-500">
                                                                        Portal
                                                                        Account:
                                                                    </span>
                                                                    {r.associationStatus ===
                                                                    'verified' ? (
                                                                        <span className="inline-flex items-center gap-1 rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-[10px] font-bold text-green-700">
                                                                            ✓
                                                                            Staff-Verified
                                                                        </span>
                                                                    ) : (
                                                                        <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600">
                                                                            Unlinked
                                                                            /
                                                                            Offline
                                                                        </span>
                                                                    )}
                                                                </div>

                                                                {r.associationStatus ===
                                                                    'verified' &&
                                                                    r.residentName && (
                                                                        <div className="text-xs text-gray-700">
                                                                            <p className="font-medium">
                                                                                Linked:{' '}
                                                                                {
                                                                                    r.residentName
                                                                                }
                                                                            </p>
                                                                            {r.residentNumber && (
                                                                                <p className="text-[11px] text-gray-500">
                                                                                    ID:{' '}
                                                                                    {
                                                                                        r.residentNumber
                                                                                    }
                                                                                </p>
                                                                            )}
                                                                            {r.verifiedAt && (
                                                                                <p className="text-[10px] text-gray-400">
                                                                                    Verified:{' '}
                                                                                    {
                                                                                        r.verifiedAt
                                                                                    }
                                                                                </p>
                                                                            )}
                                                                        </div>
                                                                    )}

                                                                <div className="flex gap-2 border-t border-gray-100 pt-1">
                                                                    {r.associationStatus ===
                                                                    'verified' ? (
                                                                        <>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() =>
                                                                                    openAssociateModal(
                                                                                        r,
                                                                                        'link_verify',
                                                                                    )
                                                                                }
                                                                                className="text-xs font-semibold text-[#3f6f3d] hover:underline"
                                                                            >
                                                                                Change
                                                                                Account
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                onClick={() =>
                                                                                    openAssociateModal(
                                                                                        r,
                                                                                        'unlink',
                                                                                    )
                                                                                }
                                                                                className="text-xs font-semibold text-red-600 hover:underline"
                                                                            >
                                                                                Unlink
                                                                            </button>
                                                                        </>
                                                                    ) : (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() =>
                                                                                openAssociateModal(
                                                                                    r,
                                                                                    'link_verify',
                                                                                )
                                                                            }
                                                                            className="rounded-lg bg-[#3f6f3d] px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30]"
                                                                        >
                                                                            Link
                                                                            &
                                                                            Verify
                                                                            Resident
                                                                            Account
                                                                        </button>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                {caseData.origin === 'manual' && (
                                    <section className="space-y-2 rounded-xl border bg-white p-5">
                                        <h2 className="font-bold">
                                            Walk-in dispute record
                                        </h2>
                                        <p className="text-xs text-slate-500">
                                            {caseData.intake.incidentDate} ·{' '}
                                            {caseData.intake.incidentLocation}
                                        </p>
                                        <p className="text-sm whitespace-pre-wrap">
                                            {caseData.intake.narrative}
                                        </p>
                                        <p className="text-sm whitespace-pre-wrap text-slate-600">
                                            Requested action:{' '}
                                            {caseData.intake.requestedAction}
                                        </p>
                                    </section>
                                )}
                                {/* Right Col: Originating Complaint or Manual Case Information */}
                                <div className="space-y-6">
                                    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                                        <h2 className="border-b border-gray-100 pb-3 text-base font-bold text-gray-900">
                                            {caseData.complaint
                                                ? 'Originating Complaint Details'
                                                : 'Manual Walk-in Details'}
                                        </h2>
                                        <div className="mt-4 space-y-3 text-xs leading-5 text-gray-600">
                                            <div>
                                                <span className="font-semibold text-gray-400 uppercase">
                                                    Nature
                                                </span>
                                                <p className="text-sm font-medium text-gray-900">
                                                    {caseData.caseType}
                                                </p>
                                            </div>

                                            {caseData.complaint ? (
                                                <>
                                                    <div>
                                                        <span className="font-semibold text-gray-400 uppercase">
                                                            Incident Location
                                                        </span>
                                                        <p className="text-gray-800">
                                                            {caseData.complaint
                                                                .incidentLocation ||
                                                                'N/A'}
                                                        </p>
                                                    </div>

                                                    <div>
                                                        <span className="font-semibold text-gray-400 uppercase">
                                                            Incident Date
                                                        </span>
                                                        <p className="text-gray-800">
                                                            {caseData.complaint
                                                                .incidentDate ||
                                                                'N/A'}
                                                        </p>
                                                    </div>

                                                    <div>
                                                        <span className="font-semibold text-gray-400 uppercase">
                                                            Complainant
                                                            Narrative
                                                        </span>
                                                        <p className="mt-1 rounded-lg border border-gray-200 bg-gray-50 p-2.5 whitespace-pre-line text-gray-800">
                                                            {
                                                                caseData
                                                                    .complaint
                                                                    .narrative
                                                            }
                                                        </p>
                                                    </div>

                                                    <div>
                                                        <span className="font-semibold text-gray-400 uppercase">
                                                            Requested Action
                                                        </span>
                                                        <p className="mt-1 rounded-lg border border-gray-200 bg-gray-50 p-2.5 whitespace-pre-line text-gray-800">
                                                            {
                                                                caseData
                                                                    .complaint
                                                                    .requestedAction
                                                            }
                                                        </p>
                                                    </div>

                                                    {caseData.complaint
                                                        .luponNote && (
                                                        <div>
                                                            <span className="font-semibold text-gray-400 uppercase">
                                                                Intake Review
                                                                Note
                                                            </span>
                                                            <p className="mt-1 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-amber-900">
                                                                {
                                                                    caseData
                                                                        .complaint
                                                                        .luponNote
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                <>
                                                    <div>
                                                        <span className="font-semibold text-gray-400 uppercase">
                                                            Registration Type
                                                        </span>
                                                        <p className="font-medium text-gray-800">
                                                            Walk-in Direct Entry
                                                            (Manual Case)
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <span className="font-semibold text-gray-400 uppercase">
                                                            Filed Date
                                                        </span>
                                                        <p className="text-gray-800">
                                                            {caseData.filedDate}
                                                        </p>
                                                    </div>
                                                    {caseData.createdByName && (
                                                        <div>
                                                            <span className="font-semibold text-gray-400 uppercase">
                                                                Encoded By
                                                            </span>
                                                            <p className="text-gray-800">
                                                                {
                                                                    caseData.createdByName
                                                                }
                                                            </p>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>
                                <EvidencePanel
                                    caseId={caseData.id}
                                    files={caseData.evidence}
                                />
                            </>
                        ),
                        history: <CaseTimeline workflow={caseData.workflow} />,
                    }}
                />
            </div>

            {/* Modal: Schedule Hearing */}
            {showHearingModal &&
                createPortal(
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                            <h3 className="text-lg font-bold text-gray-900">
                                {previousHearingId
                                    ? 'Unresolved — schedule next hearing'
                                    : 'Schedule Case Hearing'}
                            </h3>
                            <form
                                onSubmit={handleScheduleHearing}
                                className="mt-4 space-y-4"
                            >
                                <Errors errors={errors} />
                                <p className="text-sm text-stone-600">
                                    {caseData.phase} phase · maximum 3 hearings.{' '}
                                    {hearingDeadline
                                        ? `Allowed until ${hearingDeadline} (15-day phase period).`
                                        : 'The first meeting starts the 15-day period.'}
                                </p>
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Hearing Date
                                    </label>
                                    <input
                                        type="date"
                                        required
                                        min={new Date().toLocaleDateString(
                                            'en-CA',
                                            { timeZone: 'Asia/Manila' },
                                        )}
                                        max={hearingDeadline}
                                        value={hearingDate}
                                        onChange={(e) =>
                                            setHearingDate(e.target.value)
                                        }
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Time
                                    </label>
                                    <input
                                        type="time"
                                        required
                                        value={hearingTime}
                                        onChange={(e) =>
                                            setHearingTime(e.target.value)
                                        }
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Venue
                                    </label>
                                    <input
                                        type="text"
                                        value={venue}
                                        onChange={(e) =>
                                            setVenue(e.target.value)
                                        }
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Proceedings Phase
                                    </label>
                                    <select
                                        value={hearingPhase}
                                        onChange={(e) =>
                                            setHearingPhase(e.target.value)
                                        }
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                    >
                                        <option value={caseData.phase}>
                                            {caseData.phase === 'Kapitan'
                                                ? 'Kapitan Mediation Phase'
                                                : 'Lupon Conciliation Phase'}
                                        </option>
                                    </select>
                                </div>

                                <div className="mt-6 flex justify-end gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowHearingModal(false)
                                        }
                                        className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={saving}
                                        aria-busy={saving}
                                        className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                                    >
                                        {saving
                                            ? 'Saving hearing schedule…'
                                            : 'Schedule'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>,
                    document.getElementById('hearing-modal-host') ??
                        document.body,
                )}

            {/* Modal: Add Complainant */}
            {showPartyModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                        <h3 className="text-lg font-bold text-gray-900">
                            Add Complainant
                        </h3>
                        <form
                            onSubmit={handleAddComplainant}
                            className="mt-4 space-y-4"
                        >
                            <Errors errors={errors} />
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Full Name
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={partyName}
                                    onChange={(e) =>
                                        setPartyName(e.target.value)
                                    }
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Address
                                </label>
                                <input
                                    type="text"
                                    value={partyAddress}
                                    onChange={(e) =>
                                        setPartyAddress(e.target.value)
                                    }
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Contact Number
                                </label>
                                <input
                                    type="text"
                                    value={partyContact}
                                    onChange={(e) =>
                                        setPartyContact(e.target.value)
                                    }
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
                                    disabled={saving}
                                    aria-busy={saving}
                                    className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                                >
                                    {saving ? 'Saving complainant…' : 'Save'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Record Settlement */}
            {showSettlementModal &&
                createPortal(
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="max-h-[88vh] w-full max-w-3xl overflow-auto rounded-2xl bg-white p-6 shadow-xl">
                            <h3 className="text-lg font-bold text-gray-900">
                                Amicable Settlement
                            </h3>
                            {caseData.settlement ? (
                                <>
                                    <p className="mt-3 text-sm whitespace-pre-wrap">
                                        {caseData.settlement.terms}
                                    </p>
                                    <SettlementDocument
                                        caseId={caseData.id}
                                        document={caseData.documents.find(
                                            (d) =>
                                                d.kind ===
                                                    'amicable_settlement' &&
                                                d.status !== 'superseded',
                                        )}
                                    />
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowSettlementModal(false)
                                        }
                                        className="mt-4 rounded-lg border px-4 py-2"
                                    >
                                        Close settlement
                                    </button>
                                </>
                            ) : (
                                <>
                                    <p className="mt-2 text-sm text-stone-600">
                                        Itala kung paano nagkasundo ang
                                        magkabilang panig at ang napagkasunduang
                                        obligasyon. Pagkatapos i-save, lalabas
                                        dito ang Amicable Settlement DOCX.
                                    </p>
                                    <form
                                        onSubmit={handleRecordSettlement}
                                        className="mt-4 space-y-4"
                                    >
                                        <Errors errors={errors} />
                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700">
                                                Settlement Type
                                            </label>
                                            <select
                                                value={settlementType}
                                                onChange={(e) =>
                                                    setSettlementType(
                                                        e.target.value,
                                                    )
                                                }
                                                className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                            >
                                                <option value="Amicable Settlement">
                                                    Amicable Settlement
                                                </option>
                                            </select>
                                        </div>

                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700">
                                                Date of Agreement
                                            </label>
                                            <input
                                                type="date"
                                                required
                                                value={settlementDate}
                                                onChange={(e) =>
                                                    setSettlementDate(
                                                        e.target.value,
                                                    )
                                                }
                                                className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-xs font-semibold text-gray-700">
                                                Dahilan ng settlement /
                                                napagkasunduang terms
                                            </label>
                                            <textarea
                                                required
                                                rows={4}
                                                value={settlementTerms}
                                                onChange={(e) =>
                                                    setSettlementTerms(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Enter stipulations, agreements, or obligations decided upon by the parties..."
                                                className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm"
                                            />
                                        </div>

                                        <div className="mt-6 flex justify-end gap-2">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setShowSettlementModal(
                                                        false,
                                                    )
                                                }
                                                className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="submit"
                                                disabled={saving}
                                                aria-busy={saving}
                                                className="rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white hover:bg-[#315b30]"
                                            >
                                                {saving
                                                    ? 'Saving settlement…'
                                                    : 'Record Agreement'}
                                            </button>
                                        </div>
                                    </form>
                                </>
                            )}
                        </div>
                    </div>,
                    document.getElementById('hearing-modal-host') ??
                        document.body,
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
                            Respondent:{' '}
                            <span className="font-semibold text-gray-800">
                                {selectedParty.name}
                            </span>
                        </p>

                        <form
                            onSubmit={handleAssociateSubmit}
                            className="mt-4 space-y-4"
                        >
                            <Errors errors={errors} />
                            {associationAction === 'link_verify' ? (
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Select Verified Barangay Resident{' '}
                                        <span className="text-red-500">*</span>
                                    </label>
                                    <ResidentAutocomplete
                                        endpoint="/lupon/residents/search"
                                        value={residentSearch}
                                        onChange={(value) => {
                                            setResidentSearch(value);
                                            setSelectedResidentId('');
                                        }}
                                        onSelect={(resident) => {
                                            setResidentSearch(
                                                resident.full_name,
                                            );
                                            setSelectedResidentId(resident.id);
                                        }}
                                    />
                                    <p className="mt-1 text-[11px] text-gray-500">
                                        Once verified, this case will be visible
                                        in the resident's "Complaints Against
                                        Me" portal tab.
                                    </p>
                                </div>
                            ) : (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                                    Are you sure you want to unlink the resident
                                    account from this respondent party? The
                                    respondent will no longer see this case in
                                    their portal.
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Verification Notes
                                </label>
                                <textarea
                                    rows={2}
                                    value={verificationNotes}
                                    onChange={(e) =>
                                        setVerificationNotes(e.target.value)
                                    }
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
                                    aria-busy={saving || associationLoading}
                                    disabled={saving || associationLoading}
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
