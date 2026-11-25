import type { PaginatedData } from '@/types';

export type AllocationRecord = {
    id: string;
    fiscal_year: number;
    name: string;
    fund_type: string;
    category: string;
    source: string;
    amount_approved: number;
    reference_no: string | null;
    status: string;
    created_by: string;
    amount_used: number;
    remaining_balance: number;
    percentage_used: number;
};

export type AllocationSummary = {
    fiscal_year: number | null;
    total_approved: number;
    total_amendments: number;
    adjusted_budget: number;
    record_count: number;
    sources: Array<{ source: string; total: number }>;
};

export type MandatoryFund = {
    label: string;
    total: number;
};

export type AllocationDetails = {
    allocation: AllocationRecord;
    balance: {
        approved: number;
        amendments: number;
        available: number;
        used: number;
        remaining: number;
    };
    amendments: Array<{
        id: string;
        amendment_date: string | null;
        amount: number;
        reference_no: string | null;
        reason: string | null;
        created_by: string;
    }>;
    disbursements: Array<{
        id: string;
        disbursement_date: string | null;
        payee: string;
        amount: number;
        status: string;
    }>;
};

export type CollectionRecord = {
    id: string;
    collection_date: string | null;
    collection_type: string;
    certificate_id: number | null;
    certificate_type: string;
    certificate_code: string | null;
    amount: number;
    or_number: string | null;
    payee_name: string;
    certificate_request_id: number | null;
    certificate_reference: string | null;
    is_waived: boolean;
    waive_reason: string | null;
    status: string;
    encoded_by: string;
    verified_by: string | null;
    verified_at: string | null;
    void_reason: string | null;
};

export type PendingCertificate = {
    id: number;
    reference: string;
    payee_name: string;
    certificate_name: string;
    amount: number;
    status: string;
};

export type DisbursementRecord = {
    id: string;
    disbursement_date: string | null;
    allocation_id: string;
    allocation_name: string;
    fiscal_year: number;
    payee: string;
    purpose: string;
    amount: number;
    dv_number: string | null;
    status: string;
    prepared_by: string;
    approved_by: string | null;
    released_by: string | null;
    void_reason: string | null;
};

export type ReportAllocation = {
    id: string;
    fiscal_year: number;
    name: string;
    category: string;
    source: string;
    status: string;
    amount_approved: number;
    amendments: number;
    amount_used: number;
    amount_released: number;
    remaining_balance: number;
    percentage_used: number;
};

export type FinancialAuditEntry = {
    id: number;
    event: string;
    entity_type: string | null;
    actor: string;
    created_at: string | null;
    context: Record<string, unknown>;
};

export type BlockedSections = {
    project_status: string;
    bfdp_checklist: string;
    publication: string;
    documents: string;
    attachments: string;
};

export type FinancialFlash = {
    success?: string;
    error?: string;
};

export type AllocationPageProps = {
    allocations: PaginatedData<AllocationRecord>;
    summary: AllocationSummary;
    filterOptions: {
        categories: string[];
        fund_types: string[];
        sources: string[];
        statuses: string[];
    };
    filters: {
        search: string;
        fiscal_year: number | '';
        category: string;
        fund_type: string;
        source: string;
        status: string;
        sort: string;
    };
    categories: string[];
    fundSources: string[];
    mandatoryFunds: MandatoryFund[];
    flash?: FinancialFlash;
};

export type CollectionAnalytics = {
    period: string;
    date: string;
    date_from: string;
    date_to: string;
    granularity: string;
    timezone: string;
    boundary: string;
    totals: {
        amount: number;
        paid_count: number;
        recorded_count: number;
        waived_count: number;
        pending_count: number;
        voided_count: number;
    };
    by_certificate_type: Array<{
        label: string;
        amount: number;
        count: number;
    }>;
    trend: Array<{
        key: string;
        label: string;
        amount: number;
        count: number;
    }>;
};

export type CollectionPageProps = {
    collections: PaginatedData<CollectionRecord>;
    summary: {
        verified_amount: number;
        pending_count: number;
        voided_count: number;
        waived_count: number;
    };
    analytics: CollectionAnalytics;
    filterOptions: {
        collection_types: string[];
        statuses: string[];
        certificates: Array<{ value: string; label: string }>;
    };
    pendingCertificates: PendingCertificate[];
    filters: {
        search: string;
        or_number: string;
        payee_name: string;
        collection_type: string;
        period: string;
        date: string;
        date_from: string;
        date_to: string;
        status: string;
        waived: string;
        certificate: string;
        certificate_id: number | '';
        sort: string;
    };
    flash?: FinancialFlash;
};

export type DisbursementPageProps = {
    disbursements: PaginatedData<DisbursementRecord>;
    summary: {
        released_amount: number;
        draft_count: number;
        approved_count: number;
        voided_count: number;
    };
    allocationOptions: Array<{ id: string; label: string }>;
    filters: {
        search: string;
        payee: string;
        purpose: string;
        dv_number: string;
        date_from: string;
        date_to: string;
        status: string;
        allocation_id: string;
        sort: string;
    };
    statuses: string[];
    flash?: FinancialFlash;
};

export type StatusAndReportsPageProps = {
    summary: {
        total_collections: number;
        total_disbursements: number;
        total_approved: number;
        total_amendments: number;
        adjusted_budget: number;
        used_budget: number;
        percentage_used: number;
        available_balance: number;
    };
    allocations: PaginatedData<ReportAllocation>;
    auditHistory: PaginatedData<FinancialAuditEntry>;
    publicationPeriods: Array<{
        id: string;
        year: number;
        month: number;
        label: string;
        is_published: boolean;
        published_at: string | null;
        published_by: string;
    }>;
    attachments: Array<{
        id: string;
        title: string;
        record_type: string;
        uploaded_by: string;
        uploaded_at: string | null;
        url: string;
        download_url: string;
    }>;
    filterOptions: {
        allocations: Array<{ id: string; label: string }>;
        periods: Array<{ year: number; month: number; label: string }>;
        statuses: string[];
    };
    blockedSections: BlockedSections;
    filters: {
        search: string;
        year: number | '';
        fiscal_year: number | '';
        month: number | '';
        quarter: number | '';
        status: string;
        allocation_id: string;
        sort: string;
    };
    flash?: FinancialFlash;
};

export type ResidentFinancialDisclosurePageProps = {
    period: {
        year: number;
        month: number;
        label: string;
        published_at: string | null;
    };
    periods: Array<{ year: number; month: number; label: string }>;
    summary: {
        collections: number;
        disbursements: number;
        available_balance: number;
    };
    collections: Array<{
        id: string;
        collection_date: string | null;
        collection_type: string;
        amount: number;
    }>;
    disbursements: Array<{
        id: string;
        disbursement_date: string | null;
        allocation_name: string;
        purpose: string;
        amount: number;
    }>;
    allocations: Array<{
        id: string;
        name: string;
        category: string;
        source: string;
        amount_approved: number;
        amount_used: number;
        remaining_balance: number;
    }>;
    attachments: Array<{
        id: string;
        title: string;
        uploaded_at: string | null;
        url: string;
    }>;
};

export function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    }).format(Number.isFinite(value) ? value : 0);
}

export function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString('en-PH');
}
