import type { LuponWorkflow } from './types';

type Party = {
    id: number;
    name: string;
    address?: string;
    contactNumber?: string;
    isPrimary: boolean;
};
type OverviewCase = {
    status: string;
    phase: string;
    filedDate: string;
    assignedStaffName?: string;
    complainants: Party[];
    respondents: Party[];
    intake: {
        narrative?: string | null;
        requestedAction?: string | null;
        incidentDate?: string | null;
        incidentLocation?: string | null;
    };
    complaint?: {
        narrative: string;
        requestedAction: string;
        incidentDate?: string;
        incidentLocation?: string;
    } | null;
    workflow: LuponWorkflow;
};

export default function CaseOverview({ caseData }: { caseData: OverviewCase }) {
    const complaint = caseData.complaint ?? caseData.intake;
    return (
        <>
            <div className="grid gap-4 md:grid-cols-2">
                {(
                    [
                        ['Complainant', caseData.complainants],
                        ['Respondent', caseData.respondents],
                    ] as const
                ).map(([label, parties]) => (
                    <section
                        key={label}
                        className="rounded-xl border border-stone-200 bg-white p-4"
                    >
                        <h2 className="font-bold text-stone-900">{label}</h2>
                        {parties.length ? (
                            <ul className="mt-3 divide-y divide-stone-100">
                                {parties.map((party) => (
                                    <li key={party.id} className="py-2 text-sm">
                                        <p className="font-semibold">
                                            {party.name}
                                            {party.isPrimary && (
                                                <span className="ml-2 text-xs font-normal text-stone-500">
                                                    Primary
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap text-stone-600">
                                            {party.address ||
                                                'Address not recorded'}
                                        </p>
                                        {party.contactNumber && (
                                            <p className="text-stone-600">
                                                {party.contactNumber}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-2 text-sm text-stone-500">
                                No party recorded.
                            </p>
                        )}
                    </section>
                ))}
            </div>
            <section className="rounded-xl border border-stone-200 bg-white p-4">
                <h2 className="font-bold">Complaint details</h2>
                <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt className="text-stone-500">
                            Incident date (recorded)
                        </dt>
                        <dd className="font-semibold">
                            {complaint.incidentDate || 'Not recorded'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-stone-500">Date filed</dt>
                        <dd className="font-semibold">{caseData.filedDate}</dd>
                    </div>
                </dl>
                <p className="mt-3 text-sm text-stone-600">
                    Location: {complaint.incidentLocation || 'Not recorded'}
                </p>
                <details className="mt-3">
                    <summary className="cursor-pointer text-sm font-semibold text-[#3b6fa8]">
                        Read salaysay and requested action
                    </summary>
                    <p className="mt-3 text-sm whitespace-pre-wrap">
                        {complaint.narrative || 'No narrative recorded.'}
                    </p>
                    <p className="mt-3 text-sm whitespace-pre-wrap">
                        <strong>Requested action:</strong>{' '}
                        {complaint.requestedAction || 'Not recorded'}
                    </p>
                </details>
            </section>
        </>
    );
}
