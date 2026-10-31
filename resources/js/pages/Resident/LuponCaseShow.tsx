import { Deferred, Head, Link } from '@inertiajs/react';
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
            fallback={<ResidentLayout active="lupon"><PortalPageSkeleton list /></ResidentLayout>}
        >
            <LuponCaseContent {...props} />
        </Deferred>
    );
}

function LuponCaseContent({ viewerRole = 'complainant', caseData }: Props) {
    return (
        <ResidentLayout active="lupon">
            <Head title={`Case ${caseData.caseNumber}`} />

            <div className="mx-auto w-full max-w-4xl space-y-6">
                <div className="flex items-center justify-between">
                    <Link
                        href="/resident/lupon"
                        className="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-600 hover:text-gray-900"
                    >
                        {viewerRole === 'respondent' ? '← Back to Complaints Against Me' : '← Back to My Complaints'}
                    </Link>

                    {caseData.complaintReference && (
                        <span className="text-xs text-gray-500">
                            Complaint: <span className="font-semibold text-gray-700">{caseData.complaintReference}</span>
                        </span>
                    )}
                </div>

                {/* Case Banner */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-bold text-gray-900">{caseData.caseNumber}</h1>
                                <span className="rounded-full bg-[#e8f1e4] px-3 py-0.5 text-xs font-semibold text-[#3f6f3d]">
                                    {caseData.phase} Phase
                                </span>
                                <span className="rounded-full bg-blue-50 border border-blue-200 px-3 py-0.5 text-xs font-semibold text-blue-700">
                                    {caseData.status}
                                </span>
                            </div>

                            <p className="mt-1 text-base font-semibold text-gray-800">{caseData.title}</p>
                            <p className="text-xs text-gray-500">Filed on {caseData.filedDate}</p>
                        </div>
                    </div>
                </div>

                {/* Case Summary Card */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                    <h2 className="text-base font-bold text-gray-900 border-b border-gray-100 pb-3">Case Information</h2>
                    
                    <div className="grid gap-4 sm:grid-cols-2 text-sm">
                        <div>
                            <span className="text-xs text-gray-400 font-semibold uppercase">Nature of Dispute</span>
                            <p className="font-medium text-gray-800">{caseData.caseType}</p>
                        </div>
                        <div>
                            <span className="text-xs text-gray-400 font-semibold uppercase">Respondent</span>
                            <p className="font-medium text-gray-800">{caseData.respondentName}</p>
                        </div>
                    </div>

                    {caseData.narrative && (
                        <div>
                            <span className="text-xs text-gray-400 font-semibold uppercase">Complaint Narrative</span>
                            <p className="mt-1 rounded-xl bg-gray-50 p-3 text-xs leading-5 text-gray-700 whitespace-pre-line border border-gray-100">
                                {caseData.narrative}
                            </p>
                        </div>
                    )}
                </div>

                {/* Scheduled Hearings */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h2 className="text-base font-bold text-gray-900 border-b border-gray-100 pb-3">Proceedings & Hearings</h2>
                    
                    <div className="mt-4">
                        {caseData.hearings.length === 0 ? (
                            <p className="text-sm text-gray-500 text-center py-6">
                                No hearings have been scheduled yet. You will be notified when a schedule is posted.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {caseData.hearings.map((h) => (
                                    <div key={h.id} className="rounded-xl border border-gray-200 p-4">
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-gray-900">Hearing #{h.number} ({h.phase} Phase)</span>
                                            <span className="rounded-full bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                                                {h.status}
                                            </span>
                                        </div>

                                        <div className="mt-2 text-xs text-gray-600 space-y-1">
                                            <p>📅 <strong>Date:</strong> {h.date} {h.time && `at ${h.time}`}</p>
                                            <p>📍 <strong>Venue:</strong> {h.venue}</p>
                                            {h.outcome && <p>⚖️ <strong>Outcome:</strong> {h.outcome}</p>}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Settlement Card if available */}
                {caseData.settlement && (
                    <div className="rounded-2xl border border-green-200 bg-green-50/50 p-6 shadow-sm">
                        <div className="flex items-center justify-between">
                            <h2 className="text-base font-bold text-green-900">Official Settlement / Resolution</h2>
                            <span className="rounded-full bg-green-100 px-3 py-0.5 text-xs font-bold text-green-800">
                                {caseData.settlement.status}
                            </span>
                        </div>

                        <p className="mt-1 text-xs text-green-700">Type: {caseData.settlement.type} on {caseData.settlement.date}</p>

                        <div className="mt-3 rounded-xl bg-white p-4 border border-green-200 text-xs text-gray-800 whitespace-pre-line leading-5">
                            {caseData.settlement.terms}
                        </div>
                    </div>
                )}
            </div>
        </ResidentLayout>
    );
}
