import type { LuponDocument, LuponStatus, LuponWorkflow } from './types';

/** Display selection only. A schedule never establishes attendance/completion. */
export function hearingSummary<
    T extends {
        id: number;
        phase: string;
        date: string;
        time?: string | null;
        status: string;
    },
>(hearings: T[], currentPhase?: string): T | undefined {
    const dated = [...hearings].sort(
        (a, b) =>
            new Date(a.date).getTime() - new Date(b.date).getTime() ||
            (a.time || '').localeCompare(b.time || '') ||
            a.id - b.id,
    );
    const scheduled = dated.filter((hearing) =>
        ['Scheduled', 'Rescheduled'].includes(hearing.status),
    );
    return (
        scheduled.find((hearing) => hearing.phase === currentPhase) ??
        scheduled[0] ??
        dated.at(-1)
    );
}

export type CaseSection =
    | 'overview'
    | 'hearings'
    | 'documents'
    | 'resolution'
    | 'details'
    | 'history';
export const caseSections: { id: CaseSection; label: string }[] = [
    { id: 'overview', label: 'Case details' },
    { id: 'hearings', label: 'Hearings' },
    { id: 'history', label: 'History' },
];

/** Navigation from supplied records only, never a transition/readiness decision. */
export function caseGuide(
    status: LuponStatus,
    hearings: { id: number; status: string }[],
    documents: LuponDocument[],
    hasSettlement: boolean,
) {
    if (
        hasSettlement ||
        [
            'settled',
            'dismissed',
            'repudiated',
            'certified_to_file_action',
        ].includes(status)
    )
        return {
            title: 'Review the recorded case outcome',
            description:
                'Read the recorded result and any existing authorized actions in Overview. No new stage is inferred.',
            action: 'Review case details',
            section: 'overview' as CaseSection,
        };
    if (
        hearings.some((hearing) =>
            ['Scheduled', 'Rescheduled'].includes(hearing.status),
        )
    )
        return {
            title: 'Review the scheduled hearing',
            description:
                'A schedule is recorded. Inspect its status, outcome and related documents without assuming attendance or completion.',
            action: 'View hearings',
            section: 'hearings' as CaseSection,
        };
    if (documents.length)
        return {
            title: 'Review the document records',
            description:
                'Preparation and recorded service are shown separately. A prepared document does not establish service.',
            action: 'Open hearing records',
            section: 'hearings' as CaseSection,
        };
    return {
        title: 'Review case details and current-stage actions',
        description:
            'Confirm the recorded parties and complaint. Open the action area to inspect existing options; prerequisites still require verification.',
        action: 'Review case details',
        section: 'overview' as CaseSection,
    };
}

export function recordedStages(workflow: LuponWorkflow) {
    const recorded = new Set(workflow.events.map((event) => event.to));
    if (workflow.milestones.mediation_started_at)
        recorded.add('mediation_active');
    if (workflow.milestones.pangkat_formed_at) recorded.add('pangkat_formed');
    if (workflow.milestones.conciliation_started_at)
        recorded.add('conciliation_active');
    const statuses = [
        ...new Set([
            'filed',
            'mediation_active',
            'pangkat_formed',
            'conciliation_active',
            ...recorded,
            workflow.status,
        ]),
    ];
    return statuses.map((status) => ({
        status,
        state:
            status === workflow.status
                ? 'current'
                : recorded.has(status)
                  ? 'recorded'
                  : workflow.transitions.includes(status as LuponStatus)
                    ? 'verification'
                    : 'unavailable',
    }));
}
