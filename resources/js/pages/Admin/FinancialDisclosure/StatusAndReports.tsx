import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    Banknote,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    Clock3,
    Download,
    Eye,
    FileText,
    Paperclip,
    ShieldCheck,
    Upload,
    Wallet,
} from 'lucide-react';

import Pagination from '@/components/Pagination';
import {
    FinancialAlert,
    FinancialField,
    FinancialFilterBar,
} from '@/components/financial-disclosure/controls';
import {
    FinancialEmptyTableRow,
    FinancialMetric,
    FinancialNotice,
    FinancialPageHeader,
    FinancialSection,
    FinancialStatusBadge,
    FinancialTableShell,
} from '@/components/financial-disclosure/ui';
import AdminLayout from '@/layouts/AdminLayout';
import {
    formatCurrency,
    formatDate,
    type StatusAndReportsPageProps,
} from '@/types/financialDisclosure';

const baseUrl = '/admin/financial-disclosure/status-reports';

type FilterForm = {
    search: string;
    year: number | '';
    fiscal_year: number | '';
    month: number | '';
    quarter: number | '';
    status: string;
    allocation_id: string;
    sort: string;
};

const projectStatuses = [
    { label: 'Planned', tone: 'neutral' as const, icon: Clock3 },
    { label: 'Ongoing', tone: 'info' as const, icon: BarChart3 },
    { label: 'Completed', tone: 'success' as const, icon: CheckCircle2 },
];

const attachmentTypes = ['DV', 'Receipt', 'Notice of Award'];

function statusTone(status: string) {
    if (status === 'Released' || status === 'Verified')
        return 'success' as const;
    if (status === 'Approved') return 'info' as const;
    if (status === 'Voided') return 'danger' as const;

    return 'warning' as const;
}

function formatContext(context: Record<string, unknown>): string {
    const entries = Object.entries(context)
        .filter(
            ([, value]) =>
                value !== null && value !== '' && value !== undefined,
        )
        .slice(0, 3)
        .map(([key, value]) => `${key}: ${String(value)}`);

    return entries.length > 0 ? entries.join(' · ') : 'No extra context';
}

export default function StatusAndReports() {
    const {
        summary,
        allocations,
        auditHistory,
        publicationPeriods,
        attachments,
        filterOptions,
        blockedSections,
        filters,
        flash,
    } = usePage<StatusAndReportsPageProps>().props;

    const [filterForm, setFilterForm] = useState<FilterForm>({ ...filters });
    const [publicationForm, setPublicationForm] = useState({
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
        publish: true,
    });
    const attachmentForm = useForm<{
        title: string;
        record_type: string;
        record_id: string;
        file: File | null;
    }>({
        title: '',
        record_type: 'general',
        record_id: '',
        file: null,
    });
    const [busy, setBusy] = useState(false);
    const [navigationError, setNavigationError] = useState<string | null>(null);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setBusy(true);
        setNavigationError(null);

        router.get(
            baseUrl,
            { ...filterForm },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setBusy(false),
                onError: () =>
                    setNavigationError('The report could not be filtered.'),
            },
        );
    };

    const clearFilters = () => {
        const empty: FilterForm = {
            search: '',
            year: '',
            fiscal_year: '',
            month: '',
            quarter: '',
            status: '',
            allocation_id: '',
            sort: 'fiscal_year_desc',
        };
        setFilterForm(empty);
        setBusy(true);
        router.get(
            baseUrl,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    const usePeriod = (label: string) => {
        const [year, month] = label.split('-');
        const next = {
            ...filterForm,
            year: Number(year),
            month: Number(month),
        };
        setFilterForm(next);
        setBusy(true);
        router.get(baseUrl, next, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setBusy(false),
        });
    };

    const exportUrl = (type: string) => {
        const params = new URLSearchParams();

        Object.entries(filterForm).forEach(([key, value]) => {
            if (value !== '') {
                params.set(key, String(value));
            }
        });

        return `${baseUrl}/exports/${type}?${params.toString()}`;
    };

    const submitPublication = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setBusy(true);
        setNavigationError(null);

        router.post(`${baseUrl}/publication`, publicationForm, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onError: () =>
                setNavigationError('The disclosure period could not be saved.'),
        });
    };

    const submitAttachment = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        attachmentForm.post('/admin/financial-disclosure/attachments', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => attachmentForm.reset(),
        });
    };

    const rows = Array.isArray(allocations.data) ? allocations.data : [];
    const auditRows = Array.isArray(auditHistory.data) ? auditHistory.data : [];
    const years = Array.from(
        new Set([
            ...filterOptions.periods.map((period) => period.year),
            ...rows.map((row) => row.fiscal_year),
        ]),
    ).sort((first, second) => second - first);

    return (
        <>
            <Head title="Financial Disclosure - Status & Reports" />
            <AdminLayout active="financial-disclosure-status-reports">
                <div className="mx-auto max-w-7xl space-y-8 p-4 sm:p-6 lg:p-8">
                    <FinancialPageHeader
                        eyebrow="Financial Disclosure / Admin"
                        title="Status & Reports — Financial Disclosure"
                        description="Review database-backed collections, disbursements, allocation balances, reporting filters, and existing audit history."
                    />

                    <FinancialAlert
                        success={flash?.success}
                        error={navigationError ?? flash?.error}
                    />

                    <FinancialNotice title="Report source rules" tone="warning">
                        Collections count only verified, non-voided, non-waived
                        records. Disbursements count only released records.
                        Available balance uses approved amounts plus signed
                        amendments minus released disbursements.
                    </FinancialNotice>

                    <section aria-labelledby="financial-overview">
                        <h2
                            id="financial-overview"
                            className="text-ink-strong mb-4 text-lg font-semibold"
                        >
                            Financial overview
                        </h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FinancialMetric
                                label="Total collections"
                                value={formatCurrency(
                                    summary.total_collections,
                                )}
                                hint="Verified, non-voided, non-waived collections."
                                icon={Banknote}
                            />
                            <FinancialMetric
                                label="Total disbursements"
                                value={formatCurrency(
                                    summary.total_disbursements,
                                )}
                                hint="Released disbursements."
                                icon={Wallet}
                            />
                            <FinancialMetric
                                label="Approved budget"
                                value={formatCurrency(summary.total_approved)}
                                hint="Original allocation amounts."
                                icon={FileText}
                            />
                            <FinancialMetric
                                label="Adjusted budget"
                                value={formatCurrency(summary.adjusted_budget)}
                                hint={`Includes ${formatCurrency(summary.total_amendments)} in signed amendments.`}
                                icon={BarChart3}
                            />
                            <FinancialMetric
                                label="Budget used"
                                value={formatCurrency(summary.used_budget)}
                                hint={`${summary.percentage_used}% of the adjusted budget.`}
                                icon={ShieldCheck}
                            />
                            <FinancialMetric
                                label="Available balance"
                                value={formatCurrency(
                                    summary.available_balance,
                                )}
                                hint="Approved plus signed amendments minus released disbursements."
                                icon={ShieldCheck}
                            />
                            <FinancialMetric
                                label="Allocation records"
                                value={String(allocations.total)}
                                hint="Records matching the active report filters."
                                icon={BarChart3}
                            />
                        </div>
                    </section>

                    <FinancialSection
                        id="report-filters"
                        title="Report filters"
                        description="Saved records are filtered by period, status, allocation, and fiscal year."
                    >
                        <FinancialFilterBar
                            onSubmit={applyFilters}
                            onReset={clearFilters}
                            busy={busy}
                        >
                            <FinancialField label="Search">
                                <input
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.search}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            search: event.target.value,
                                        })
                                    }
                                    placeholder="Allocation or reference"
                                />
                            </FinancialField>
                            <FinancialField label="Year">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.year}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            year:
                                                event.target.value === ''
                                                    ? ''
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                >
                                    <option value="">All years</option>
                                    {years.map((year) => (
                                        <option key={year} value={year}>
                                            {year}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Fiscal year">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.fiscal_year}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            fiscal_year:
                                                event.target.value === ''
                                                    ? ''
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                >
                                    <option value="">All fiscal years</option>
                                    {years.map((year) => (
                                        <option key={year} value={year}>
                                            {year}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Month">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.month}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            month:
                                                event.target.value === ''
                                                    ? ''
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                >
                                    <option value="">All months</option>
                                    {[
                                        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12,
                                    ].map((month) => (
                                        <option key={month} value={month}>
                                            {month}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Quarter">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.quarter}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            quarter:
                                                event.target.value === ''
                                                    ? ''
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                >
                                    <option value="">All quarters</option>
                                    {[1, 2, 3, 4].map((quarter) => (
                                        <option key={quarter} value={quarter}>
                                            Q{quarter}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Status">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.status}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            status: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All statuses</option>
                                    {filterOptions.statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Allocation">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.allocation_id}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            allocation_id: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All allocations</option>
                                    {filterOptions.allocations.map(
                                        (allocation) => (
                                            <option
                                                key={allocation.id}
                                                value={allocation.id}
                                            >
                                                {allocation.label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </FinancialField>
                            <FinancialField label="Sort">
                                <select
                                    className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                    value={filterForm.sort}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            sort: event.target.value,
                                        })
                                    }
                                >
                                    <option value="fiscal_year_desc">
                                        Fiscal year ↓
                                    </option>
                                    <option value="fiscal_year_asc">
                                        Fiscal year ↑
                                    </option>
                                    <option value="name_asc">PPA A–Z</option>
                                    <option value="name_desc">PPA Z–A</option>
                                    <option value="amount_desc">
                                        Approved ↓
                                    </option>
                                    <option value="amount_asc">
                                        Approved ↑
                                    </option>
                                </select>
                            </FinancialField>
                        </FinancialFilterBar>

                        {filterOptions.periods.length > 0 ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    Available periods:
                                </span>
                                {filterOptions.periods
                                    .slice(0, 12)
                                    .map((period) => (
                                        <button
                                            key={period.label}
                                            type="button"
                                            onClick={() =>
                                                usePeriod(period.label)
                                            }
                                            className="border-line-soft text-ink hover:bg-canvas rounded-full border bg-white px-3 py-1 text-xs font-medium transition"
                                        >
                                            {period.label}
                                        </button>
                                    ))}
                            </div>
                        ) : null}
                    </FinancialSection>

                    <FinancialSection
                        id="report-exports"
                        title="Downloadable reports"
                        description="Collections CSV exports realized collections by default. Applying a status filter exports the raw ledger instead."
                    >
                        <div className="border-line-soft flex flex-wrap gap-3 rounded-xl border bg-white p-5">
                            <a
                                href={exportUrl('collections')}
                                className="text-brand-deep text-sm font-semibold underline"
                            >
                                Collections CSV
                            </a>
                            <a
                                href={exportUrl('disbursements')}
                                className="text-brand-deep text-sm font-semibold underline"
                            >
                                Disbursements CSV
                            </a>
                            <a
                                href={exportUrl('allocations')}
                                className="text-brand-deep text-sm font-semibold underline"
                            >
                                Allocations CSV
                            </a>
                            <a
                                href={exportUrl('status-of-funds')}
                                className="text-brand-deep text-sm font-semibold underline"
                            >
                                Status of funds CSV
                            </a>
                        </div>
                    </FinancialSection>

                    <FinancialSection
                        id="allocation-vs-actual"
                        title="Allocation vs. actual per PPA"
                        description="Saved allocation, amendment, used, released, and remaining amounts."
                    >
                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[1180px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">FY</th>
                                        <th className="px-5 py-3">PPA</th>
                                        <th className="px-5 py-3">Category</th>
                                        <th className="px-5 py-3">Source</th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">Approved</th>
                                        <th className="px-5 py-3">
                                            Amendments
                                        </th>
                                        <th className="px-5 py-3">Used</th>
                                        <th className="px-5 py-3">Released</th>
                                        <th className="px-5 py-3">Remaining</th>
                                        <th className="px-5 py-3">Used %</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {rows.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={11}
                                            title="No allocation report rows found"
                                            description="Create an allocation or adjust the active report filters."
                                        />
                                    ) : (
                                        rows.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="bg-white"
                                            >
                                                <td className="tnum px-5 py-3">
                                                    {row.fiscal_year}
                                                </td>
                                                <td className="text-ink-strong px-5 py-3 font-medium">
                                                    {row.name}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {row.category}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {row.source}
                                                </td>
                                                <td className="px-5 py-3">
                                                    <FinancialStatusBadge
                                                        tone={statusTone(
                                                            row.status,
                                                        )}
                                                    >
                                                        {row.status}
                                                    </FinancialStatusBadge>
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        row.amount_approved,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        row.amendments,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        row.amount_used,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        row.amount_released,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        row.remaining_balance,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {row.percentage_used}%
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </FinancialTableShell>
                        <Pagination
                            paginator={allocations}
                            label="Allocation report"
                        />
                    </FinancialSection>

                    <FinancialSection
                        id="financial-audit-history"
                        title="Financial audit history"
                        description="Create, update, verify, void, approve, release, and amendment events from the existing audit model."
                    >
                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[880px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">Event</th>
                                        <th className="px-5 py-3">
                                            Related Record
                                        </th>
                                        <th className="px-5 py-3">Actor</th>
                                        <th className="px-5 py-3">Timestamp</th>
                                        <th className="px-5 py-3">Context</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {auditRows.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={5}
                                            title="No financial audit events found"
                                            description="Financial actions will appear here as records are created and processed."
                                        />
                                    ) : (
                                        auditRows.map((entry) => (
                                            <tr
                                                key={entry.id}
                                                className="bg-white"
                                            >
                                                <td className="text-ink-strong px-5 py-3 font-medium">
                                                    {entry.event}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {entry.entity_type ??
                                                        'Financial record'}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {entry.actor}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {formatDate(
                                                        entry.created_at,
                                                    )}
                                                </td>
                                                <td
                                                    className="max-w-[22rem] truncate px-5 py-3"
                                                    title={formatContext(
                                                        entry.context,
                                                    )}
                                                >
                                                    {formatContext(
                                                        entry.context,
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </FinancialTableShell>
                        <Pagination
                            paginator={auditHistory}
                            label="Financial audit"
                        />
                    </FinancialSection>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <FinancialSection
                            id="project-status"
                            title="Project status"
                            description={blockedSections.project_status}
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                {projectStatuses.map((status) => (
                                    <div
                                        key={status.label}
                                        className="border-line-soft shadow-card rounded-xl border bg-white p-5"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="flex items-center gap-3">
                                                <span className="bg-brand-soft text-brand-deep flex size-9 items-center justify-center rounded-lg">
                                                    <status.icon className="size-[18px]" />
                                                </span>
                                                <p className="text-ink-strong text-sm font-semibold">
                                                    {status.label}
                                                </p>
                                            </div>
                                            <FinancialStatusBadge
                                                tone={status.tone}
                                            >
                                                Unavailable
                                            </FinancialStatusBadge>
                                        </div>
                                        <p className="text-ink-muted mt-4 text-xs leading-5">
                                            No approved project-status model is
                                            connected.
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </FinancialSection>

                        <FinancialSection
                            id="bfdp-checklist"
                            title="BFDP compliance checklist"
                            description={blockedSections.bfdp_checklist}
                        >
                            <FinancialTableShell>
                                <table className="text-ink-muted w-full min-w-[520px] text-left text-sm">
                                    <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                        <tr>
                                            <th className="px-5 py-3">
                                                Checklist Item
                                            </th>
                                            <th className="px-5 py-3">
                                                Status
                                            </th>
                                            <th className="px-5 py-3">
                                                Remarks
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-line-soft divide-y">
                                        <FinancialEmptyTableRow
                                            colSpan={3}
                                            title="No checklist items available"
                                            description={
                                                blockedSections.bfdp_checklist
                                            }
                                        />
                                    </tbody>
                                </table>
                            </FinancialTableShell>
                        </FinancialSection>
                    </div>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <FinancialSection
                            id="disclosure-publication"
                            title="Disclosure period publication"
                            description="Publish or unpublish a year and month for resident access."
                        >
                            <div className="border-line-soft shadow-card space-y-4 rounded-xl border bg-white p-5">
                                <div className="flex items-start gap-3">
                                    <span className="bg-brand-soft text-brand-deep flex size-9 shrink-0 items-center justify-center rounded-lg">
                                        <Eye className="size-[18px]" />
                                    </span>
                                    <div>
                                        <p className="text-ink-strong text-sm font-semibold">
                                            Admin-controlled publication
                                        </p>
                                        <p className="text-ink-muted mt-1 text-sm leading-6">
                                            Residents can view only periods that
                                            are published for this barangay.
                                        </p>
                                    </div>
                                </div>
                                <form
                                    onSubmit={submitPublication}
                                    className="grid grid-cols-1 gap-3 sm:grid-cols-4"
                                >
                                    <FinancialField label="Year" required>
                                        <input
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            inputMode="numeric"
                                            value={publicationForm.year}
                                            onChange={(event) =>
                                                setPublicationForm({
                                                    ...publicationForm,
                                                    year: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                    </FinancialField>
                                    <FinancialField label="Month" required>
                                        <select
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            value={publicationForm.month}
                                            onChange={(event) =>
                                                setPublicationForm({
                                                    ...publicationForm,
                                                    month: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                        >
                                            {Array.from({ length: 12 }).map(
                                                (_, index) => (
                                                    <option
                                                        key={index + 1}
                                                        value={index + 1}
                                                    >
                                                        {index + 1}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </FinancialField>
                                    <FinancialField label="Action" required>
                                        <select
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            value={
                                                publicationForm.publish
                                                    ? 'publish'
                                                    : 'unpublish'
                                            }
                                            onChange={(event) =>
                                                setPublicationForm({
                                                    ...publicationForm,
                                                    publish:
                                                        event.target.value ===
                                                        'publish',
                                                })
                                            }
                                        >
                                            <option value="publish">
                                                Publish
                                            </option>
                                            <option value="unpublish">
                                                Unpublish
                                            </option>
                                        </select>
                                    </FinancialField>
                                    <div className="flex items-end">
                                        <button
                                            type="submit"
                                            disabled={busy}
                                            className="bg-brand-deep rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                                        >
                                            Save publication
                                        </button>
                                    </div>
                                </form>
                                <div className="space-y-2">
                                    {publicationPeriods.length === 0 ? (
                                        <p className="text-ink-muted text-sm">
                                            No disclosure periods have been
                                            published.
                                        </p>
                                    ) : (
                                        publicationPeriods.map((item) => (
                                            <div
                                                key={item.id}
                                                className="border-line-soft bg-surface flex items-center justify-between gap-3 rounded-lg border px-4 py-3"
                                            >
                                                <div>
                                                    <p className="text-ink-strong text-sm font-medium">
                                                        {item.label}
                                                    </p>
                                                    <p className="text-ink-muted text-xs">
                                                        {item.is_published
                                                            ? `Published by ${item.published_by}`
                                                            : 'Not published'}
                                                    </p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            `${baseUrl}/publication`,
                                                            {
                                                                year: item.year,
                                                                month: item.month,
                                                                publish:
                                                                    !item.is_published,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    className="text-brand-deep text-xs font-semibold underline"
                                                >
                                                    {item.is_published
                                                        ? 'Unpublish'
                                                        : 'Publish'}
                                                </button>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        </FinancialSection>

                        <FinancialSection
                            id="financial-documents"
                            title="Supplementary financial documents"
                            description={blockedSections.documents}
                        >
                            <div className="border-line-soft shadow-card space-y-4 rounded-xl border bg-white p-5">
                                <form
                                    onSubmit={submitAttachment}
                                    className="grid grid-cols-1 gap-3 sm:grid-cols-2"
                                >
                                    <FinancialField
                                        label="Title"
                                        required
                                        error={attachmentForm.errors.title}
                                    >
                                        <input
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            value={attachmentForm.data.title}
                                            onChange={(event) =>
                                                attachmentForm.setData(
                                                    'title',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FinancialField>
                                    <FinancialField
                                        label="Record type"
                                        required
                                        error={attachmentForm.errors.record_type}
                                    >
                                        <select
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            value={attachmentForm.data.record_type}
                                            onChange={(event) =>
                                                attachmentForm.setData(
                                                    'record_type',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="general">General</option>
                                            <option value="disclosure_period">
                                                Disclosure period
                                            </option>
                                            <option value="allocation">
                                                Allocation
                                            </option>
                                            <option value="collection">
                                                Collection
                                            </option>
                                            <option value="disbursement">
                                                Disbursement
                                            </option>
                                        </select>
                                    </FinancialField>
                                    <FinancialField
                                        label="Record ID"
                                        error={attachmentForm.errors.record_id}
                                        hint="Leave blank for general documents."
                                    >
                                        <input
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            value={attachmentForm.data.record_id}
                                            onChange={(event) =>
                                                attachmentForm.setData(
                                                    'record_id',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="UUID"
                                        />
                                    </FinancialField>
                                    <FinancialField
                                        label="PDF or image"
                                        required
                                        error={attachmentForm.errors.file}
                                        hint="PDF, JPG, JPEG, or PNG up to 10 MB."
                                    >
                                        <input
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            className="border-line-soft text-ink-strong rounded-lg bg-white text-sm"
                                            onChange={(event) =>
                                                attachmentForm.setData(
                                                    'file',
                                                    event.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </FinancialField>
                                    <div className="sm:col-span-2">
                                        <button
                                            type="submit"
                                            disabled={attachmentForm.processing}
                                            className="bg-brand-deep inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                                        >
                                            <Upload className="size-4" />
                                            Upload document
                                        </button>
                                    </div>
                                </form>
                                <div className="space-y-2">
                                    {attachments.length === 0 ? (
                                        <p className="text-ink-muted text-sm">
                                            No documents have been uploaded.
                                        </p>
                                    ) : (
                                        attachments.map((attachment) => (
                                            <div
                                                key={attachment.id}
                                                className="border-line-soft bg-surface flex items-center justify-between gap-3 rounded-lg border px-4 py-3"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-ink-strong truncate text-sm font-medium">
                                                        {attachment.title}
                                                    </p>
                                                    <p className="text-ink-muted text-xs">
                                                        {attachment.record_type} ·{' '}
                                                        {attachment.uploaded_by} ·{' '}
                                                        {formatDate(
                                                            attachment.uploaded_at,
                                                        )}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-3">
                                                    <a
                                                        href={attachment.url}
                                                        className="text-brand-deep text-xs font-semibold underline"
                                                    >
                                                        View
                                                    </a>
                                                    <a
                                                        href={
                                                            attachment.download_url
                                                        }
                                                        className="text-brand-deep inline-flex items-center gap-1 text-xs font-semibold underline"
                                                    >
                                                        <Download className="size-3" />
                                                        Download
                                                    </a>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        </FinancialSection>
                    </div>

                    <FinancialSection
                        id="disbursement-attachments"
                        title="Disbursement attachments"
                        description={blockedSections.attachments}
                    >
                        <div className="border-line-soft shadow-card grid grid-cols-1 gap-3 rounded-xl border bg-white p-5 sm:grid-cols-3">
                            {attachmentTypes.map((type) => (
                                <div
                                    key={type}
                                    className="border-line-soft bg-surface flex items-center gap-3 rounded-lg border px-4 py-3"
                                >
                                    <Paperclip className="text-ink-muted size-4" />
                                    <span className="text-ink-strong text-sm font-medium">
                                        {type}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </FinancialSection>

                    <p className="text-ink-faint flex items-center gap-2 text-xs">
                        <CalendarDays className="size-4" />
                        Reports use saved financial records; unavailable
                        sections require approved rules or schema.
                    </p>
                </div>
            </AdminLayout>
        </>
    );
}
