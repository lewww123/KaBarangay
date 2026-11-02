export const luponStatuses = {
    filed: 'Filed',
    mediation_active: 'Mediation active',
    pangkat_formed: 'Pangkat formed',
    conciliation_active: 'Conciliation active',
    settled: 'Settled',
    repudiated: 'Repudiated',
    dismissed: 'Dismissed',
    certified_to_file_action: 'Certified to file action',
} as const;
export type LuponStatus = keyof typeof luponStatuses;
export type LuponWorkflow = {
    status: LuponStatus;
    statusLabel: string;
    trackingStatus: string;
    milestones: {
        mediation_started_at: string | null;
        pangkat_formed_at: string | null;
        conciliation_started_at: string | null;
        pangkat_members?: string[];
        pangkat_chair_name?: string | null;
        pangkat_secretary_name?: string | null;
    };
    transitions: LuponStatus[];
    deadlines: {
        label: string;
        date: string;
        daysRemaining: number;
        active?: boolean;
    }[];
    events: {
        id: number;
        from: string | null;
        to: string;
        date: string;
        notes?: string | null;
        metadata?: Record<string, unknown> | null;
    }[];
};
export type LuponEvidenceFile = {
    id: number;
    name: string;
    size: number;
    mime: string;
    shared: boolean;
    url: string;
};
export type LuponDocumentKind =
    | 'notice_of_hearing'
    | 'summons'
    | 'amicable_settlement'
    | 'cfa';
export type LuponDocument = {
    id: number;
    kind: LuponDocumentKind;
    label: string;
    status: 'pending' | 'ready' | 'superseded';
    hearingId: number | null;
    generatedAt: string | null;
    servedAt: string | null;
    url: string | null;
};
export type LuponOfficial = { id: number; name: string; title: string };
export type LuponOfficials = {
    default_punong_barangay?: LuponOfficial | null;
    punong_barangay: LuponOfficial | null;
    active_kagawads: LuponOfficial[];
    kagawads: LuponOfficial[];
};
export type LuponParty = {
    name: string;
    residentId: string;
    isResident: boolean;
    address: string;
    contact: string;
};
export type LuponResident = {
    id: number;
    name: string;
    resident_number: string;
    address: string;
    contact: string;
};
export function luponStatusLabel(status: string): string {
    return (luponStatuses as Record<string, string>)[status] || status;
}
export function luponStatusClasses(status: string): string {
    if (['settled', 'Settled', 'Executed'].includes(status))
        return 'bg-emerald-50 text-emerald-800 border-emerald-200';
    if (
        [
            'repudiated',
            'dismissed',
            'Rejected',
            'Dismissed',
            'Repudiated',
        ].includes(status)
    )
        return 'bg-red-50 text-red-800 border-red-200';
    if (['certified_to_file_action', 'CFA Issued'].includes(status))
        return 'bg-violet-50 text-violet-800 border-violet-200';
    if (
        [
            'mediation_active',
            'conciliation_active',
            'pangkat_formed',
            'Summoned',
        ].includes(status)
    )
        return 'bg-blue-50 text-blue-800 border-blue-200';
    return 'bg-amber-50 text-amber-800 border-amber-200';
}
export function luponError(error: unknown): string {
    if (typeof error === 'object' && error && 'response' in error) {
        const response = error.response as { data?: { message?: unknown } };
        if (typeof response.data?.message === 'string')
            return response.data.message;
    }
    return 'The lookup could not be completed. Try again or use manual entry.';
}
