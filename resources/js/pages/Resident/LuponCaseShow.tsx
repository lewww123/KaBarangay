import CaseTimeline from '@/components/lupon/CaseTimeline';
import { EvidenceList } from '@/components/lupon/Evidence';
import {
    luponStatusLabel,
    type LuponWorkflow,
    type LuponDocument,
    type LuponEvidenceFile,
} from '@/components/lupon/types';
import { Deferred, Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, MapPin, Scale } from 'lucide-react';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';

type Props = {
    viewerRole?: 'complainant' | 'respondent';
    caseData: {
        id: number;
        caseNumber: string;
        origin?: string;
        title: string;
        caseType: string;
        phase: string;
        status: string;
        workflow: LuponWorkflow;
        documents: LuponDocument[];
        evidence: LuponEvidenceFile[];
        filedDate: string;
        complaintReference?: string;
        narrative?: string;
        requestedAction?: string;
        respondentName: string;
        complainantName?: string;
        hearings: Array<{
            id: number;
            number: number;
            phase: string;
            date: string;
            time?: string;
            venue: string;
            status: string;
            outcome?: string;
        }>;
        settlement?: {
            type: string;
            date: string;
            terms: string;
            status: string;
        } | null;
    };
};

export default function ResidentLuponCaseShow(props: Props) {
    return (
        <Deferred
            data="caseData"
            fallback={
                <ResidentLayout active="lupon">
                    <PortalPageSkeleton list />
                </ResidentLayout>
            }
        >
            <LuponCaseContent {...props} />
        </Deferred>
    );
}

function LuponCaseContent({ viewerRole = 'complainant', caseData }: Props) {
    usePoll(15000, { only: ['caseData'] });
    return (
        <ResidentLayout active="lupon">
            <Head title={`Case ${caseData.caseNumber}`} />

            <div className="mx-auto w-full max-w-4xl space-y-6">
                <div className="flex items-center justify-between">
                    <Link
                        href="/resident/lupon"
                        className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-muted hover:text-ink-strong"
                    >
                        <ArrowLeft className="size-4" />
                        {viewerRole === 'respondent'
                            ? 'Back to Complaints Against Me'
                            : 'Back to My Complaints'}
                    </Link>

                    {caseData.complaintReference && (
                        <span className="text-xs text-ink-muted">
                            Complaint:{' '}
                            <span className="font-semibold text-ink">
                                {caseData.complaintReference}
                            </span>
                        </span>
                    )}
                </div>

                {/* Case Banner */}
                <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                                    {caseData.caseNumber}
                                </h1>
                                <span className="rounded-full bg-brand-soft px-3 py-0.5 text-xs font-semibold text-brand">
                                    {caseData.phase} Phase
                                </span>
                                <span className="rounded-full border border-blue-200 bg-blue-50 px-3 py-0.5 text-xs font-semibold text-blue-700">
                                    {luponStatusLabel(caseData.status)}
                                </span>
                            </div>

                            <p className="mt-1 text-base font-semibold tracking-tight text-ink-strong">
                                {caseData.title}
                            </p>
                            <p className="text-xs text-ink-muted">
                                Filed on {caseData.filedDate}
                            </p>
                        </div>
                    </div>
                </div>

                <CaseTimeline workflow={caseData.workflow} />
                <section className="space-y-3 rounded-xl border bg-white p-5">
                    <h2 className="font-semibold">Case documents</h2>
                    {caseData.documents.length ? (
                        <ul className="space-y-2">
                            {caseData.documents.map((document) => (
                                <li key={document.id}>
                                    <a
                                        href={document.url || undefined}
                                        className="text-sm font-semibold text-brand underline hover:text-brand-deep"
                                    >
                                        {document.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-ink-muted">
                            Released documents will appear here.
                        </p>
                    )}
                </section>
                <section className="space-y-3 rounded-xl border bg-white p-5">
                    <h2 className="font-semibold">Evidence available to you</h2>
                    <EvidenceList files={caseData.evidence} />
                </section>
                {/* Case Summary Card */}
                <div className="space-y-4 rounded-xl border border-line-soft bg-white p-5 shadow-card">
                    <h2 className="border-b border-line-soft pb-3 text-base font-semibold tracking-tight text-ink-strong">
                        Case Information
                    </h2>

                    <div className="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <span className="text-xs font-semibold text-ink-faint uppercase">
                                Nature of Dispute
                            </span>
                            <p className="font-medium text-ink-strong">
                                {caseData.caseType}
                            </p>
                        </div>
                        <div>
                            <span className="text-xs font-semibold text-ink-faint uppercase">
                                Respondent
                            </span>
                            <p className="font-medium text-ink-strong">
                                {caseData.respondentName}
                            </p>
                        </div>
                    </div>

                    {caseData.narrative && (
                        <div>
                            <span className="text-xs font-semibold text-ink-faint uppercase">
                                Complaint Narrative
                            </span>
                            <p className="mt-1 rounded-xl border border-line-soft bg-canvas p-3 text-xs leading-5 whitespace-pre-line text-ink">
                                {caseData.narrative}
                            </p>
                        </div>
                    )}
                </div>

                {/* Scheduled Hearings */}
                <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card">
                    <h2 className="border-b border-line-soft pb-3 text-base font-semibold tracking-tight text-ink-strong">
                        Proceedings & Hearings
                    </h2>

                    <div className="mt-4">
                        {caseData.hearings.length === 0 ? (
                            <p className="py-6 text-center text-sm text-ink-muted">
                                No hearings have been scheduled yet. You will be
                                notified when a schedule is posted.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {caseData.hearings.map((h) => (
                                    <div
                                        key={h.id}
                                        className="rounded-xl border border-line-soft p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold text-ink-strong">
                                                Hearing #{h.number} ({h.phase}{' '}
                                                Phase)
                                            </span>
                                            <span className="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                                                {h.status}
                                            </span>
                                        </div>

                                        <div className="mt-2 space-y-1 text-xs text-ink-muted">
                                            <p className="flex items-center gap-1.5">
                                                <CalendarDays className="size-3.5 shrink-0" />
                                                <span>
                                                    <strong>Date:</strong>{' '}
                                                    {h.date}{' '}
                                                    {h.time && `at ${h.time}`}
                                                </span>
                                            </p>
                                            <p className="flex items-center gap-1.5">
                                                <MapPin className="size-3.5 shrink-0" />
                                                <span>
                                                    <strong>Venue:</strong>{' '}
                                                    {h.venue}
                                                </span>
                                            </p>
                                            {h.outcome && (
                                                <p className="flex items-center gap-1.5">
                                                    <Scale className="size-3.5 shrink-0" />
                                                    <span>
                                                        <strong>Outcome:</strong>{' '}
                                                        {h.outcome}
                                                    </span>
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Settlement Card if available */}
                {caseData.settlement && (
                    <div className="rounded-xl border border-brand-muted bg-brand-soft/50 p-6 shadow-card">
                        <div className="flex items-center justify-between">
                            <h2 className="text-base font-semibold text-brand-deep">
                                Official Settlement / Resolution
                            </h2>
                            <span className="rounded-full bg-brand-soft px-3 py-0.5 text-xs font-semibold text-brand-deep">
                                {caseData.settlement.status}
                            </span>
                        </div>

                        <p className="mt-1 text-xs text-brand-deep">
                            Type: {caseData.settlement.type} on{' '}
                            {caseData.settlement.date}
                        </p>

                        <div className="mt-3 rounded-xl border border-brand-muted bg-white p-4 text-xs leading-5 whitespace-pre-line text-ink-strong">
                            {caseData.settlement.terms}
                        </div>
                    </div>
                )}
            </div>
        </ResidentLayout>
    );
}
