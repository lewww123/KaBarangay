import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    Banknote,
    Building2,
    CalendarDays,
    CircleDollarSign,
    FilePlus2,
    Layers3,
    Pencil,
    ShieldAlert,
} from 'lucide-react';

import Pagination from '@/components/Pagination';
import {
    FinancialActionButton,
    FinancialAlert,
    FinancialField,
    FinancialFilterBar,
    FinancialModal,
} from '@/components/financial-disclosure/controls';
import {
    FinancialEmptyState,
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
    type AllocationDetails,
    type AllocationPageProps,
    type AllocationRecord,
} from '@/types/financialDisclosure';

const baseUrl = '/admin/financial-disclosure/allocations';

const allocationStatuses = ['Active', 'Amended', 'Closed', 'Archived'];

type AllocationFormData = {
    fiscal_year: string;
    name: string;
    fund_type: string;
    category: string;
    source: string;
    amount_approved: string;
    reference_no: string;
    status: string;
};

type FilterForm = {
    search: string;
    fiscal_year: string;
    category: string;
    fund_type: string;
    source: string;
    status: string;
    sort: string;
};

function statusTone(status: string) {
    if (status === 'Active') return 'success' as const;
    if (status === 'Amended') return 'info' as const;
    if (status === 'Closed') return 'neutral' as const;
    if (status === 'Archived') return 'warning' as const;

    return 'brand' as const;
}

const inputClass =
    'block w-full rounded-lg border-line-soft bg-white text-sm text-ink-strong placeholder:text-ink-faint';

export default function Allocation() {
    const {
        allocations,
        summary,
        filterOptions,
        filters,
        categories,
        fundSources,
        mandatoryFunds,
        flash,
    } = usePage<AllocationPageProps>().props;

    const [filterForm, setFilterForm] = useState<FilterForm>({
        search: filters.search,
        fiscal_year:
            filters.fiscal_year === '' ? '' : String(filters.fiscal_year),
        category: filters.category,
        fund_type: filters.fund_type,
        source: filters.source,
        status: filters.status,
        sort: filters.sort,
    });
    const [busy, setBusy] = useState(false);
    const [navigationError, setNavigationError] = useState<string | null>(null);

    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<AllocationRecord | null>(null);
    const [amending, setAmending] = useState<AllocationRecord | null>(null);
    const [details, setDetails] = useState<AllocationDetails | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [detailsLoading, setDetailsLoading] = useState(false);
    const [detailsError, setDetailsError] = useState<string | null>(null);

    const createForm = useForm<AllocationFormData>({
        fiscal_year: String(new Date().getFullYear()),
        name: '',
        fund_type: '',
        category: categories[0] ?? 'PS',
        source: fundSources[0] ?? '',
        amount_approved: '',
        reference_no: '',
        status: 'Active',
    });

    const editForm = useForm<AllocationFormData>({
        fiscal_year: '',
        name: '',
        fund_type: '',
        category: 'PS',
        source: '',
        amount_approved: '',
        reference_no: '',
        status: '',
    });

    const amendmentForm = useForm({
        amount: '',
        reference_no: '',
        reason: '',
    });

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setBusy(true);
        setNavigationError(null);

        router.get(
            baseUrl,
            {
                search: filterForm.search || undefined,
                fiscal_year: filterForm.fiscal_year || undefined,
                category: filterForm.category || undefined,
                fund_type: filterForm.fund_type || undefined,
                source: filterForm.source || undefined,
                status: filterForm.status || undefined,
                sort: filterForm.sort,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setBusy(false),
                onError: () =>
                    setNavigationError(
                        'The allocation list could not be filtered.',
                    ),
            },
        );
    };

    const clearFilters = () => {
        const empty: FilterForm = {
            search: '',
            fiscal_year: '',
            category: '',
            fund_type: '',
            source: '',
            status: '',
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

    const openEdit = (record: AllocationRecord) => {
        setEditing(record);
        editForm.setData({
            fiscal_year: String(record.fiscal_year),
            name: record.name,
            fund_type: record.fund_type,
            category: record.category,
            source: record.source,
            amount_approved: String(record.amount_approved),
            reference_no: record.reference_no ?? '',
            status: record.status,
        });
    };

    const openDetails = async (record: AllocationRecord) => {
        setDetailsLoading(true);
        setDetailsError(null);
        setDetailsOpen(true);

        try {
            const response = await fetch(`${baseUrl}/${record.id}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error('request failed');
            }

            setDetails((await response.json()) as AllocationDetails);
        } catch {
            setDetailsError('The allocation details could not be loaded.');
        } finally {
            setDetailsLoading(false);
        }
    };

    const submitCreate = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        createForm.post(baseUrl, {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                setCreateOpen(false);
            },
        });
    };

    const submitEdit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!editing) return;

        editForm.put(`${baseUrl}/${editing.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditing(null);
            },
        });
    };

    const submitAmendment = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!amending) return;

        amendmentForm.post(`${baseUrl}/${amending.id}/amendments`, {
            preserveScroll: true,
            onSuccess: () => {
                amendmentForm.reset();
                setAmending(null);
            },
        });
    };

    const setStatus = (record: AllocationRecord, status: string) => {
        router.put(
            `${baseUrl}/${record.id}`,
            {
                fiscal_year: record.fiscal_year,
                name: record.name,
                fund_type: record.fund_type,
                category: record.category,
                source: record.source,
                reference_no: record.reference_no,
                status,
            },
            { preserveScroll: true },
        );
    };

    const rows = Array.isArray(allocations.data) ? allocations.data : [];

    return (
        <>
            <Head title="Financial Disclosure - Allocation" />
            <AdminLayout active="financial-disclosure-allocation">
                <div className="mx-auto max-w-7xl space-y-8 p-4 sm:p-6 lg:p-8">
                    <FinancialPageHeader
                        eyebrow="Financial Disclosure / Admin"
                        title="Allocation — Budget Management"
                        description="Manage database-backed allocations, budget amendments, and per-PPA balances inside the Admin Portal."
                    />

                    <FinancialAlert
                        success={flash?.success}
                        error={navigationError ?? flash?.error}
                    />

                    <FinancialNotice
                        title="Database-backed records"
                        tone="info"
                    >
                        Totals and balances are computed from saved allocations,
                        signed amendments, and released disbursements. Allocation
                        amounts are changed through amendment records, not by
                        overwriting the original amount.
                    </FinancialNotice>

                    <section aria-labelledby="allocation-overview">
                        <h2
                            id="allocation-overview"
                            className="text-ink-strong mb-4 text-lg font-semibold"
                        >
                            Allocation overview
                        </h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FinancialMetric
                                label="Fiscal year"
                                value={
                                    summary.fiscal_year
                                        ? String(summary.fiscal_year)
                                        : 'No records'
                                }
                                hint="Based on the active fiscal year filter or latest saved year."
                                icon={CalendarDays}
                            />
                            <FinancialMetric
                                label="Total approved budget"
                                value={formatCurrency(summary.total_approved)}
                                hint="Derived from saved allocation amounts."
                                icon={Banknote}
                            />
                            <FinancialMetric
                                label="Signed amendments"
                                value={formatCurrency(summary.total_amendments)}
                                hint="Negative values reduce the approved budget."
                                icon={ShieldAlert}
                            />
                            <FinancialMetric
                                label="Adjusted budget"
                                value={formatCurrency(summary.adjusted_budget)}
                                hint="Approved budget plus signed amendments."
                                icon={CircleDollarSign}
                            />
                            <FinancialMetric
                                label="Allocation records"
                                value={String(summary.record_count)}
                                hint="Records available for the selected scope."
                                icon={Layers3}
                            />
                            <FinancialMetric
                                label="Fund sources"
                                value={String(
                                    summary.sources.filter(
                                        (source) => source.total > 0,
                                    ).length,
                                )}
                                hint="Sources with approved amounts in the selected fiscal year."
                                icon={CircleDollarSign}
                            />
                        </div>
                    </section>

                    <FinancialSection
                        id="allocation-records"
                        title="Allocation records"
                        description="Search, filter, sort, and page through saved allocations."
                    >
                        <div className="flex justify-end">
                            <FinancialActionButton
                                onClick={() => setCreateOpen(true)}
                            >
                                <FilePlus2 className="size-4" />
                                New allocation
                            </FinancialActionButton>
                        </div>

                        <FinancialFilterBar
                            onSubmit={applyFilters}
                            onReset={clearFilters}
                            busy={busy}
                        >
                            <FinancialField label="Search">
                                <input
                                    className={inputClass}
                                    value={filterForm.search}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            search: event.target.value,
                                        })
                                    }
                                    placeholder="Name, reference, fund source"
                                />
                            </FinancialField>
                            <FinancialField label="Fiscal year">
                                <input
                                    className={inputClass}
                                    inputMode="numeric"
                                    value={filterForm.fiscal_year}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            fiscal_year: event.target.value,
                                        })
                                    }
                                    placeholder="YYYY"
                                />
                            </FinancialField>
                            <FinancialField label="Category">
                                <select
                                    className={inputClass}
                                    value={filterForm.category}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            category: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All categories</option>
                                    {categories.map((category) => (
                                        <option key={category} value={category}>
                                            {category}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Status">
                                <select
                                    className={inputClass}
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
                            <FinancialField label="Fund type">
                                <select
                                    className={inputClass}
                                    value={filterForm.fund_type}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            fund_type: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All fund types</option>
                                    {filterOptions.fund_types.map(
                                        (fundType) => (
                                            <option
                                                key={fundType}
                                                value={fundType}
                                            >
                                                {fundType}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </FinancialField>
                            <FinancialField label="Source">
                                <select
                                    className={inputClass}
                                    value={filterForm.source}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            source: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All sources</option>
                                    {filterOptions.sources.map((source) => (
                                        <option key={source} value={source}>
                                            {source}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Sort">
                                <select
                                    className={inputClass}
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
                                    <option value="name_asc">Name A–Z</option>
                                    <option value="name_desc">Name Z–A</option>
                                    <option value="amount_desc">
                                        Amount ↓
                                    </option>
                                    <option value="amount_asc">Amount ↑</option>
                                </select>
                            </FinancialField>
                        </FinancialFilterBar>

                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[1180px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">
                                            Fiscal Year
                                        </th>
                                        <th className="px-5 py-3">
                                            PPA / Allocation
                                        </th>
                                        <th className="px-5 py-3">Category</th>
                                        <th className="px-5 py-3">
                                            Fund Source
                                        </th>
                                        <th className="px-5 py-3">
                                            Approved Amount
                                        </th>
                                        <th className="px-5 py-3">Reference</th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">
                                            Amount Used
                                        </th>
                                        <th className="px-5 py-3">
                                            Remaining Balance
                                        </th>
                                        <th className="px-5 py-3">% Used</th>
                                        <th className="px-5 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {rows.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={11}
                                            title="No allocation records found"
                                            description="Create an allocation or adjust the active filters."
                                        />
                                    ) : (
                                        rows.map((record) => (
                                            <tr
                                                key={record.id}
                                                className="bg-white"
                                            >
                                                <td className="tnum px-5 py-3">
                                                    {record.fiscal_year}
                                                </td>
                                                <td className="text-ink-strong px-5 py-3 font-medium">
                                                    {record.name}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.category}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.source}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        record.amount_approved,
                                                    )}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.reference_no ?? '—'}
                                                </td>
                                                <td className="px-5 py-3">
                                                    <FinancialStatusBadge
                                                        tone={statusTone(
                                                            record.status,
                                                        )}
                                                    >
                                                        {record.status}
                                                    </FinancialStatusBadge>
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        record.amount_used,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        record.remaining_balance,
                                                    )}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {record.percentage_used}%
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                void openDetails(
                                                                    record,
                                                                )
                                                            }
                                                            className="text-brand-deep text-xs font-semibold underline"
                                                        >
                                                            View
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                openEdit(record)
                                                            }
                                                            className="text-brand-deep inline-flex items-center gap-1 text-xs font-semibold underline"
                                                        >
                                                            <Pencil className="size-3" />
                                                            Edit
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setAmending(
                                                                    record,
                                                                );
                                                                amendmentForm.reset();
                                                            }}
                                                            className="text-brand-deep text-xs font-semibold underline"
                                                        >
                                                            Amendment
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setStatus(
                                                                    record,
                                                                    record.status ===
                                                                    'Archived'
                                                                        ? 'Active'
                                                                        : 'Archived',
                                                                )
                                                            }
                                                            className="text-brand-deep text-xs font-semibold underline"
                                                        >
                                                            {record.status ===
                                                            'Archived'
                                                                ? 'Restore'
                                                                : 'Archive'}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </FinancialTableShell>

                        <Pagination
                            paginator={allocations}
                            label="Allocation"
                        />
                    </FinancialSection>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <FinancialSection
                            id="sources-of-funds"
                            title="Sources of Funds"
                            description="Approved sources with totals derived from saved allocations."
                        >
                            <div className="border-line-soft shadow-card rounded-xl border bg-white p-5">
                                <div className="space-y-3">
                                    {summary.sources.map((source) => (
                                        <div
                                            key={source.source}
                                            className="border-line-soft bg-surface flex items-center justify-between gap-3 rounded-lg border px-4 py-3"
                                        >
                                            <span className="text-ink-strong text-sm font-medium">
                                                {source.source}
                                            </span>
                                            <span className="tnum text-brand-deep text-sm font-semibold">
                                                {formatCurrency(source.total)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </FinancialSection>

                        <FinancialSection
                            id="mandatory-funds"
                            title="Mandatory Funds"
                            description="Totals are computed from saved allocations and signed amendments."
                        >
                            <div className="border-line-soft shadow-card rounded-xl border bg-white p-5">
                                <div className="space-y-3">
                                    {mandatoryFunds.map((fund) => (
                                        <div
                                            key={fund.label}
                                            className="border-line-soft bg-surface flex items-center justify-between gap-3 rounded-lg border px-4 py-3"
                                        >
                                            <span className="text-ink-strong text-sm font-medium">
                                                {fund.label}
                                            </span>
                                            <span className="text-ink-strong tnum text-sm font-semibold">
                                                {formatCurrency(fund.total)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </FinancialSection>
                    </div>

                    <FinancialNotice title="Integrity rules" tone="neutral">
                        Amendments are stored as separate records and may reduce
                        the approved amount. Amount Used and Remaining Balance
                        use saved, released disbursements. Allocation records are
                        not deleted.
                    </FinancialNotice>

                    {createOpen ? (
                        <FinancialModal
                            title="New allocation"
                            description="Create a database-backed allocation record."
                            onClose={() => {
                                createForm.reset();
                                setCreateOpen(false);
                            }}
                        >
                            <form
                                onSubmit={submitCreate}
                                className="grid grid-cols-1 gap-4 sm:grid-cols-2"
                            >
                                <FinancialField
                                    label="Fiscal year"
                                    required
                                    error={createForm.errors.fiscal_year}
                                >
                                    <input
                                        className={inputClass}
                                        value={createForm.data.fiscal_year}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'fiscal_year',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Name"
                                    required
                                    error={createForm.errors.name}
                                >
                                    <input
                                        className={inputClass}
                                        value={createForm.data.name}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Fund type"
                                    required
                                    error={createForm.errors.fund_type}
                                >
                                    <input
                                        className={inputClass}
                                        value={createForm.data.fund_type}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'fund_type',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Category"
                                    required
                                    error={createForm.errors.category}
                                >
                                    <select
                                        className={inputClass}
                                        value={createForm.data.category}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'category',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {categories.map((category) => (
                                            <option
                                                key={category}
                                                value={category}
                                            >
                                                {category}
                                            </option>
                                        ))}
                                    </select>
                                </FinancialField>
                                <FinancialField
                                    label="Source"
                                    required
                                    error={createForm.errors.source}
                                >
                                    <input
                                        className={inputClass}
                                        list="financial-fund-sources"
                                        value={createForm.data.source}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'source',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <datalist id="financial-fund-sources">
                                        {fundSources.map((source) => (
                                            <option
                                                key={source}
                                                value={source}
                                            />
                                        ))}
                                    </datalist>
                                </FinancialField>
                                <FinancialField
                                    label="Amount approved"
                                    required
                                    error={createForm.errors.amount_approved}
                                >
                                    <input
                                        className={inputClass}
                                        inputMode="decimal"
                                        value={createForm.data.amount_approved}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'amount_approved',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Reference number"
                                    error={createForm.errors.reference_no}
                                >
                                    <input
                                        className={inputClass}
                                        value={createForm.data.reference_no}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'reference_no',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Status"
                                    required
                                    error={createForm.errors.status}
                                >
                                    <select
                                        className={inputClass}
                                        value={createForm.data.status}
                                        onChange={(event) =>
                                            createForm.setData(
                                                'status',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {allocationStatuses.map((status) => (
                                            <option key={status} value={status}>
                                                {status}
                                            </option>
                                        ))}
                                    </select>
                                </FinancialField>
                                <div className="sm:col-span-2">
                                    <FinancialActionButton
                                        type="submit"
                                        disabled={createForm.processing}
                                    >
                                        Save allocation
                                    </FinancialActionButton>
                                </div>
                            </form>
                        </FinancialModal>
                    ) : null}

                    {editing ? (
                        <FinancialModal
                            title="Edit allocation"
                            description="The approved amount is changed through amendments only."
                            onClose={() => setEditing(null)}
                        >
                            <form
                                onSubmit={submitEdit}
                                className="grid grid-cols-1 gap-4 sm:grid-cols-2"
                            >
                                <FinancialField
                                    label="Fiscal year"
                                    required
                                    error={editForm.errors.fiscal_year}
                                >
                                    <input
                                        className={inputClass}
                                        value={editForm.data.fiscal_year}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'fiscal_year',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Name"
                                    required
                                    error={editForm.errors.name}
                                >
                                    <input
                                        className={inputClass}
                                        value={editForm.data.name}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Fund type"
                                    required
                                    error={editForm.errors.fund_type}
                                >
                                    <input
                                        className={inputClass}
                                        value={editForm.data.fund_type}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'fund_type',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Category"
                                    required
                                    error={editForm.errors.category}
                                >
                                    <select
                                        className={inputClass}
                                        value={editForm.data.category}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'category',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {categories.map((category) => (
                                            <option
                                                key={category}
                                                value={category}
                                            >
                                                {category}
                                            </option>
                                        ))}
                                    </select>
                                </FinancialField>
                                <FinancialField
                                    label="Source"
                                    required
                                    error={editForm.errors.source}
                                >
                                    <input
                                        className={inputClass}
                                        value={editForm.data.source}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'source',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Reference number"
                                    error={editForm.errors.reference_no}
                                >
                                    <input
                                        className={inputClass}
                                        value={editForm.data.reference_no}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'reference_no',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Status"
                                    required
                                    error={editForm.errors.status}
                                >
                                    <select
                                        className={inputClass}
                                        value={editForm.data.status}
                                        onChange={(event) =>
                                            editForm.setData(
                                                'status',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {allocationStatuses.map((status) => (
                                            <option key={status} value={status}>
                                                {status}
                                            </option>
                                        ))}
                                    </select>
                                </FinancialField>
                                <div className="sm:col-span-2">
                                    <FinancialActionButton
                                        type="submit"
                                        disabled={editForm.processing}
                                    >
                                        Save changes
                                    </FinancialActionButton>
                                </div>
                            </form>
                        </FinancialModal>
                    ) : null}

                    {amending ? (
                        <FinancialModal
                            title="Record budget amendment"
                            description={`Amendment for ${amending.name}`}
                            onClose={() => setAmending(null)}
                        >
                            <form
                                onSubmit={submitAmendment}
                                className="space-y-4"
                            >
                                <FinancialField
                                    label="Amount"
                                    required
                                    error={amendmentForm.errors.amount}
                                    hint="Use a negative amount to reduce the approved budget."
                                >
                                    <input
                                        className={inputClass}
                                        inputMode="decimal"
                                        value={amendmentForm.data.amount}
                                        onChange={(event) =>
                                            amendmentForm.setData(
                                                'amount',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Reference number"
                                    error={amendmentForm.errors.reference_no}
                                >
                                    <input
                                        className={inputClass}
                                        value={amendmentForm.data.reference_no}
                                        onChange={(event) =>
                                            amendmentForm.setData(
                                                'reference_no',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialField
                                    label="Reason"
                                    required
                                    error={amendmentForm.errors.reason}
                                >
                                    <textarea
                                        className={inputClass}
                                        rows={3}
                                        value={amendmentForm.data.reason}
                                        onChange={(event) =>
                                            amendmentForm.setData(
                                                'reason',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialActionButton
                                    type="submit"
                                    disabled={amendmentForm.processing}
                                >
                                    Save amendment
                                </FinancialActionButton>
                            </form>
                        </FinancialModal>
                    ) : null}

                    {detailsOpen ? (
                        <FinancialModal
                            title="Allocation details"
                            description={details?.allocation.name}
                            onClose={() => setDetailsOpen(false)}
                            wide
                        >
                            {detailsLoading ? (
                                <p className="text-ink-muted text-sm">
                                    Loading details…
                                </p>
                            ) : null}
                            {detailsError ? (
                                <FinancialAlert error={detailsError} />
                            ) : null}
                            {details && !detailsLoading ? (
                                <div className="space-y-6">
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                        <FinancialMetric
                                            label="Approved"
                                            value={formatCurrency(
                                                details.balance.approved,
                                            )}
                                            hint="Original allocation amount."
                                            icon={Banknote}
                                        />
                                        <FinancialMetric
                                            label="Amendments"
                                            value={formatCurrency(
                                                details.balance.amendments,
                                            )}
                                            hint="Separate amendment records."
                                            icon={FilePlus2}
                                        />
                                        <FinancialMetric
                                            label="Used"
                                            value={formatCurrency(
                                                details.balance.used,
                                            )}
                                            hint="Non-voided disbursements."
                                            icon={Building2}
                                        />
                                        <FinancialMetric
                                            label="Remaining"
                                            value={formatCurrency(
                                                details.balance.remaining,
                                            )}
                                            hint="Available allocation balance."
                                            icon={ShieldAlert}
                                        />
                                    </div>

                                    <FinancialSection
                                        id="allocation-history"
                                        title="Amendment history"
                                        description="Preserved amendment records."
                                    >
                                        {details.amendments.length === 0 ? (
                                            <FinancialEmptyState
                                                title="No amendments"
                                                description="No amendment records are linked to this allocation."
                                            />
                                        ) : (
                                            <div className="space-y-2">
                                                {details.amendments.map(
                                                    (amendment) => (
                                                        <div
                                                            key={amendment.id}
                                                            className="border-line-soft bg-surface rounded-lg border px-4 py-3 text-sm"
                                                        >
                                                            <span className="text-ink-strong font-semibold">
                                                                {formatCurrency(
                                                                    amendment.amount,
                                                                )}
                                                            </span>
                                                            <span className="text-ink-faint mx-2">
                                                                •
                                                            </span>
                                                            <span>
                                                                {amendment.amendment_date ??
                                                                    '—'}
                                                            </span>
                                                            <span className="text-ink-faint mx-2">
                                                                •
                                                            </span>
                                                            <span>
                                                                {amendment.reference_no ??
                                                                    'No reference'}
                                                            </span>
                                                            <p className="text-ink-muted mt-1 text-xs">
                                                                {
                                                                    amendment.reason
                                                                }
                                                            </p>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </FinancialSection>

                                    <FinancialSection
                                        id="allocation-disbursements"
                                        title="Linked disbursements"
                                        description="Saved disbursement records for this allocation."
                                    >
                                        {details.disbursements.length === 0 ? (
                                            <FinancialEmptyState
                                                title="No disbursements"
                                                description="No disbursement records are linked to this allocation."
                                            />
                                        ) : (
                                            <div className="space-y-2">
                                                {details.disbursements.map(
                                                    (disbursement) => (
                                                        <div
                                                            key={
                                                                disbursement.id
                                                            }
                                                            className="border-line-soft bg-surface flex items-center justify-between gap-3 rounded-lg border px-4 py-3 text-sm"
                                                        >
                                                            <span className="text-ink-strong">
                                                                {
                                                                    disbursement.payee
                                                                }
                                                            </span>
                                                            <span className="tnum">
                                                                {formatCurrency(
                                                                    disbursement.amount,
                                                                )}
                                                            </span>
                                                            <FinancialStatusBadge>
                                                                {
                                                                    disbursement.status
                                                                }
                                                            </FinancialStatusBadge>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </FinancialSection>
                                </div>
                            ) : null}
                        </FinancialModal>
                    ) : null}
                </div>
            </AdminLayout>
        </>
    );
}
