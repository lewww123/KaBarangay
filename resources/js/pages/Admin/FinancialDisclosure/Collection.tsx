import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    BadgeCheck,
    Ban,
    CircleDollarSign,
    ClipboardCheck,
    FilePlus2,
    Pencil,
    Receipt,
    ShieldCheck,
    TrendingUp,
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
    FinancialStatusFlow,
    FinancialTableShell,
} from '@/components/financial-disclosure/ui';
import AdminLayout from '@/layouts/AdminLayout';
import {
    formatCurrency,
    formatDate,
    type CollectionPageProps,
    type CollectionRecord,
} from '@/types/financialDisclosure';

const baseUrl = '/admin/financial-disclosure/collections';

type CollectionFormData = {
    collection_date: string;
    collection_type: string;
    amount: string;
    or_number: string;
    payee_name: string;
    is_waived: boolean;
    waive_reason: string;
};

type FilterForm = {
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

function statusTone(status: string) {
    if (status === 'Verified') return 'success' as const;
    if (status === 'Voided') return 'danger' as const;

    return 'warning' as const;
}

const inputClass =
    'block w-full rounded-lg border-line-soft bg-white text-sm text-ink-strong placeholder:text-ink-faint';

export default function Collection() {
    const {
        collections,
        summary,
        analytics,
        filterOptions,
        pendingCertificates,
        filters,
        flash,
    } = usePage<CollectionPageProps>().props;

    const [filterForm, setFilterForm] = useState<FilterForm>({ ...filters });
    const [busy, setBusy] = useState(false);
    const [navigationError, setNavigationError] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<CollectionRecord | null>(null);
    const [viewing, setViewing] = useState<CollectionRecord | null>(null);
    const [voiding, setVoiding] = useState<CollectionRecord | null>(null);

    const emptyForm: CollectionFormData = {
        collection_date: new Date().toISOString().slice(0, 10),
        collection_type: '',
        amount: '',
        or_number: '',
        payee_name: '',
        is_waived: false,
        waive_reason: '',
    };

    const createForm = useForm<CollectionFormData>({ ...emptyForm });
    const editForm = useForm<CollectionFormData>({ ...emptyForm });
    const voidForm = useForm({ void_reason: '' });

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
                    setNavigationError(
                        'The collection list could not be filtered.',
                    ),
            },
        );
    };

    const clearFilters = () => {
        const empty: FilterForm = {
            search: '',
            or_number: '',
            payee_name: '',
            collection_type: '',
            period: 'monthly',
            date: '',
            date_from: analytics.date_from,
            date_to: analytics.date_to,
            status: '',
            waived: '',
            certificate: '',
            certificate_id: '',
            sort: 'date_desc',
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

    const openEdit = (record: CollectionRecord) => {
        setEditing(record);
        editForm.setData({
            collection_date: record.collection_date ?? '',
            collection_type: record.collection_type,
            amount: String(record.amount),
            or_number: record.or_number ?? '',
            payee_name: record.payee_name,
            is_waived: record.is_waived,
            waive_reason: record.waive_reason ?? '',
        });
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
            onSuccess: () => setEditing(null),
        });
    };

    const verify = (record: CollectionRecord) => {
        setNavigationError(null);
        router.post(
            `${baseUrl}/${record.id}/verify`,
            {},
            {
                preserveScroll: true,
                onError: () =>
                    setNavigationError('The collection could not be verified.'),
            },
        );
    };

    const submitVoid = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!voiding) return;

        voidForm.post(`${baseUrl}/${voiding.id}/void`, {
            preserveScroll: true,
            onSuccess: () => {
                voidForm.reset();
                setVoiding(null);
            },
        });
    };

    const rows = Array.isArray(collections.data) ? collections.data : [];

    const canVoid = (record: CollectionRecord) =>
        record.certificate_request_id === null
        || (
            record.certificate_request_status === 'For Payment'
            && !record.certificate_ready_for_claim
            && !record.certificate_released
        );

    return (
        <>
            <Head title="Financial Disclosure - Collection" />
            <AdminLayout active="financial-disclosure-collection">
                <div className="mx-auto max-w-7xl space-y-8 p-4 sm:p-6 lg:p-8">
                    <FinancialPageHeader
                        eyebrow="Financial Disclosure / Admin"
                        title="Collection — Incoming Payments"
                        description="Encode, verify, and void non-certificate collections with OR integrity and waiver handling. Clerk confirmations record certificate collections."
                    />

                    <FinancialAlert
                        success={flash?.success}
                        error={navigationError ?? flash?.error}
                    />

                    <FinancialNotice
                        title="Authoritative collections only"
                        tone="info"
                    >
                        Verified, non-voided collections are the revenue source
                        for reports. Certificate fee snapshots and payment
                        attestations are not counted as collections here.
                    </FinancialNotice>

                    <section aria-labelledby="collection-overview">
                        <h2
                            id="collection-overview"
                            className="text-ink-strong mb-4 text-lg font-semibold"
                        >
                            Collection overview
                        </h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FinancialMetric
                                label="Verified collections"
                                value={formatCurrency(summary.verified_amount)}
                                hint="Verified and non-voided amount."
                                icon={BadgeCheck}
                            />
                            <FinancialMetric
                                label="Pending"
                                value={String(summary.pending_count)}
                                hint="Awaiting verification."
                                icon={ClipboardCheck}
                            />
                            <FinancialMetric
                                label="Voided"
                                value={String(summary.voided_count)}
                                hint="Preserved with void reason."
                                icon={Ban}
                            />
                            <FinancialMetric
                                label="Waived"
                                value={String(summary.waived_count)}
                                hint="Zero-amount collections with reason."
                                icon={ShieldCheck}
                            />
                        </div>
                    </section>

                    <FinancialSection
                        id="collection-analytics"
                        title="Collection analytics"
                        description={`${analytics.boundary} Period: ${analytics.period} (${analytics.date_from} to ${analytics.date_to}).`}
                    >
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FinancialMetric
                                label="Verified amount"
                                value={formatCurrency(analytics.totals.amount)}
                                hint="Eligible verified, non-voided, non-waived collections."
                                icon={BadgeCheck}
                            />
                            <FinancialMetric
                                label="Paid transactions"
                                value={String(analytics.totals.paid_count)}
                                hint="Transactions included in the verified amount."
                                icon={CircleDollarSign}
                            />
                            <FinancialMetric
                                label="Recorded verified"
                                value={String(analytics.totals.recorded_count)}
                                hint="All verified transactions, including waived records."
                                icon={ClipboardCheck}
                            />
                            <FinancialMetric
                                label="Trend buckets"
                                value={String(analytics.trend.length)}
                                hint={`Grouped by ${analytics.granularity} in ${analytics.timezone}.`}
                                icon={TrendingUp}
                            />
                        </div>

                        <div className="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
                            <div className="border-line-soft rounded-xl border bg-white p-4">
                                <h3 className="text-ink-strong mb-3 text-sm font-semibold">
                                    By certificate type
                                </h3>
                                {analytics.by_certificate_type.length === 0 ? (
                                    <FinancialEmptyState
                                        title="No verified collections"
                                        description="Adjust the reporting period or filters to see certificate-type totals."
                                    />
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="text-ink-muted w-full text-left text-sm">
                                            <thead className="text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                                <tr>
                                                    <th className="py-2 pr-3">
                                                        Certificate
                                                    </th>
                                                    <th className="py-2 pr-3 text-right">
                                                        Count
                                                    </th>
                                                    <th className="py-2 text-right">
                                                        Amount
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-line-soft divide-y">
                                                {analytics.by_certificate_type.map(
                                                    (entry) => (
                                                        <tr key={entry.label}>
                                                            <td className="text-ink-strong py-2 pr-3 font-medium">
                                                                {entry.label}
                                                            </td>
                                                            <td className="tnum py-2 pr-3 text-right">
                                                                {entry.count}
                                                            </td>
                                                            <td className="tnum py-2 text-right">
                                                                {formatCurrency(
                                                                    entry.amount,
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            <div className="border-line-soft rounded-xl border bg-white p-4">
                                <h3 className="text-ink-strong mb-3 text-sm font-semibold">
                                    Collection trend
                                </h3>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="text-ink-muted w-full text-left text-sm">
                                        <thead className="text-ink-muted sticky top-0 bg-white text-xs font-semibold tracking-wide uppercase">
                                            <tr>
                                                <th className="py-2 pr-3">
                                                    Period
                                                </th>
                                                <th className="py-2 pr-3 text-right">
                                                    Count
                                                </th>
                                                <th className="py-2 text-right">
                                                    Amount
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-line-soft divide-y">
                                            {analytics.trend.map((entry) => (
                                                <tr key={entry.key}>
                                                    <td className="text-ink-strong py-2 pr-3 font-medium">
                                                        {entry.label}
                                                    </td>
                                                    <td className="tnum py-2 pr-3 text-right">
                                                        {entry.count}
                                                    </td>
                                                    <td className="tnum py-2 text-right">
                                                        {formatCurrency(
                                                            entry.amount,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </FinancialSection>

                    <FinancialSection
                        id="pending-certificate-collections"
                        title="Pending certificate payments"
                        description="Fee-bearing requests awaiting Clerk payment confirmation. These are informational and cannot be encoded here."
                    >
                        {pendingCertificates.length === 0 ? (
                            <FinancialEmptyState
                                title="No pending fee-bearing requests"
                                description="Certificate requests requiring payment will appear here until a Clerk confirms payment."
                            />
                        ) : (
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {pendingCertificates.map((certificate) => (
                                    <div
                                        key={certificate.id}
                                        className="border-brand-muted bg-brand-soft rounded-xl border p-4"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <p className="text-brand-deep text-sm font-semibold">
                                                {certificate.reference}
                                            </p>
                                            <FinancialStatusBadge tone="warning">
                                                {certificate.status}
                                            </FinancialStatusBadge>
                                        </div>
                                        <p className="text-brand-deep mt-2 text-sm">
                                            {certificate.payee_name}
                                        </p>
                                        <p className="tnum text-brand-deep mt-1 text-lg font-semibold">
                                            {formatCurrency(certificate.amount)}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </FinancialSection>

                    <FinancialSection
                        id="collection-records"
                        title="Collection records"
                        description="Search, filter, sort, and page through saved collections."
                    >
                        <div className="flex justify-end">
                            <FinancialActionButton
                                onClick={() => setCreateOpen(true)}
                            >
                                <FilePlus2 className="size-4" />
                                Encode collection
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
                                    placeholder="OR, payee, type"
                                />
                            </FinancialField>
                            <FinancialField label="OR number">
                                <input
                                    className={inputClass}
                                    value={filterForm.or_number}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            or_number: event.target.value,
                                        })
                                    }
                                />
                            </FinancialField>
                            <FinancialField label="Payee name">
                                <input
                                    className={inputClass}
                                    value={filterForm.payee_name}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            payee_name: event.target.value,
                                        })
                                    }
                                />
                            </FinancialField>
                            <FinancialField label="Collection type">
                                <select
                                    className={inputClass}
                                    value={filterForm.collection_type}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            collection_type: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All types</option>
                                    {filterOptions.collection_types.map(
                                        (type) => (
                                            <option key={type} value={type}>
                                                {type}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </FinancialField>
                            <FinancialField label="Reporting period">
                                <select
                                    className={inputClass}
                                    value={filterForm.period}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            period: event.target.value,
                                        })
                                    }
                                >
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="yearly">Yearly</option>
                                    <option value="range">Custom range</option>
                                </select>
                            </FinancialField>
                            {filterForm.period !== 'range' && (
                                <FinancialField label="Period date">
                                    <input
                                        type="date"
                                        className={inputClass}
                                        value={filterForm.date}
                                        onChange={(event) =>
                                            setFilterForm({
                                                ...filterForm,
                                                date: event.target.value,
                                            })
                                        }
                                    />
                                </FinancialField>
                            )}
                            {filterForm.period === 'range' && (
                                <FinancialField label="Date from">
                                    <input
                                        type="date"
                                        className={inputClass}
                                        value={filterForm.date_from}
                                        onChange={(event) =>
                                            setFilterForm({
                                                ...filterForm,
                                                date_from: event.target.value,
                                            })
                                        }
                                    />
                                </FinancialField>
                            )}
                            {filterForm.period === 'range' && (
                                <FinancialField label="Date to">
                                    <input
                                        type="date"
                                        className={inputClass}
                                        value={filterForm.date_to}
                                        onChange={(event) =>
                                            setFilterForm({
                                                ...filterForm,
                                                date_to: event.target.value,
                                            })
                                        }
                                    />
                                </FinancialField>
                            )}
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
                            <FinancialField label="Waived">
                                <select
                                    className={inputClass}
                                    value={filterForm.waived}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            waived: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All</option>
                                    <option value="1">Waived</option>
                                    <option value="0">Not waived</option>
                                </select>
                            </FinancialField>
                            <FinancialField label="Certificate link">
                                <select
                                    className={inputClass}
                                    value={filterForm.certificate}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            certificate: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All</option>
                                    <option value="1">Linked</option>
                                    <option value="0">Unlinked</option>
                                </select>
                            </FinancialField>
                            <FinancialField label="Certificate">
                                <select
                                    className={inputClass}
                                    value={filterForm.certificate_id}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            certificate_id: event.target.value
                                                ? Number(event.target.value)
                                                : '',
                                        })
                                    }
                                >
                                    <option value="">All certificates</option>
                                    {filterOptions.certificates.map(
                                        (certificate) => (
                                            <option
                                                key={certificate.value}
                                                value={certificate.value}
                                            >
                                                {certificate.label}
                                            </option>
                                        ),
                                    )}
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
                                    <option value="date_desc">Date ↓</option>
                                    <option value="date_asc">Date ↑</option>
                                    <option value="payee_asc">Payee A–Z</option>
                                    <option value="payee_desc">
                                        Payee Z–A
                                    </option>
                                    <option value="amount_desc">
                                        Amount ↓
                                    </option>
                                    <option value="amount_asc">Amount ↑</option>
                                    <option value="or_asc">OR number ↑</option>
                                    <option value="or_desc">OR number ↓</option>
                                </select>
                            </FinancialField>
                        </FinancialFilterBar>

                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[1240px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">Date</th>
                                        <th className="px-5 py-3">OR Number</th>
                                        <th className="px-5 py-3">Payee</th>
                                        <th className="px-5 py-3">Type</th>
                                        <th className="px-5 py-3">Amount</th>
                                        <th className="px-5 py-3">Waived</th>
                                        <th className="px-5 py-3">
                                            Certificate / Request
                                        </th>
                                        <th className="px-5 py-3">
                                            Encoded By
                                        </th>
                                        <th className="px-5 py-3">
                                            Verified By
                                        </th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {rows.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={11}
                                            title="No collection records found"
                                            description="Encode a collection or adjust the active filters."
                                        />
                                    ) : (
                                        rows.map((record) => (
                                            <tr
                                                key={record.id}
                                                className="bg-white"
                                            >
                                                <td className="px-5 py-3">
                                                    {formatDate(
                                                        record.collection_date,
                                                    )}
                                                </td>
                                                <td className="text-ink-strong px-5 py-3 font-medium">
                                                    {record.or_number ?? 'Waived'}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.payee_name}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.collection_type}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        record.amount,
                                                    )}
                                                </td>
                                                <td className="px-5 py-3">
                                                    <FinancialStatusBadge
                                                        tone={
                                                            record.is_waived
                                                                ? 'warning'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {record.is_waived
                                                            ? 'Waived'
                                                            : 'No'}
                                                    </FinancialStatusBadge>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="text-ink-strong font-medium">
                                                        {
                                                            record.certificate_type
                                                        }
                                                    </div>
                                                    <div className="text-ink-faint text-xs">
                                                        {record.certificate_reference ??
                                                            'Unlinked'}
                                                        {record.certificate_code
                                                            ? ` · ${record.certificate_code}`
                                                            : ''}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.encoded_by}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.verified_by ?? '—'}
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
                                                <td className="px-5 py-3">
                                                    <div className="flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setViewing(
                                                                    record,
                                                                )
                                                            }
                                                            className="text-brand-deep text-xs font-semibold underline"
                                                        >
                                                            View
                                                        </button>
                                                        {record.status ===
                                                            'Pending' &&
                                                        !record.certificate_request_id ? (
                                                            <>
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        openEdit(
                                                                            record,
                                                                        )
                                                                    }
                                                                    className="text-brand-deep inline-flex items-center gap-1 text-xs font-semibold underline"
                                                                >
                                                                    <Pencil className="size-3" />{' '}
                                                                    Edit
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        verify(
                                                                            record,
                                                                        )
                                                                    }
                                                                    className="text-brand-deep inline-flex items-center gap-1 text-xs font-semibold underline"
                                                                >
                                                                    <BadgeCheck className="size-3" />{' '}
                                                                    Verify
                                                                </button>
                                                            </>
                                                        ) : null}
                                                        {record.status !==
                                                            'Voided' &&
                                                        canVoid(record) ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setVoiding(
                                                                        record,
                                                                    );
                                                                    voidForm.reset();
                                                                }}
                                                                className="text-destructive inline-flex items-center gap-1 text-xs font-semibold underline"
                                                            >
                                                                <Ban className="size-3" />{' '}
                                                                Void
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </FinancialTableShell>

                        <Pagination
                            paginator={collections}
                            label="Collection"
                        />
                    </FinancialSection>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <FinancialSection
                            id="collection-status-flow"
                            title="Status presentation"
                            description="Saved workflow for collection records."
                        >
                            <div className="border-line-soft shadow-card rounded-xl border bg-white p-5">
                                <FinancialStatusFlow
                                    steps={['Pending', 'Verified', 'Voided']}
                                />
                                <p className="text-ink-muted mt-4 text-xs leading-5">
                                    The encoder cannot verify their own
                                    collection. Voiding preserves the record and
                                    reason.
                                </p>
                            </div>
                        </FinancialSection>

                        <FinancialSection
                            id="waived-collections"
                            title="Waived collections"
                            description="Approved waiver handling from saved records."
                        >
                            <div className="border-line-soft shadow-card rounded-xl border bg-white p-5">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-ink-strong flex items-center gap-2 text-sm font-semibold">
                                        <Receipt className="text-brand-deep size-4" />{' '}
                                        Amount
                                    </span>
                                    <span className="tnum text-sm">
                                        {formatCurrency(0)}
                                    </span>
                                </div>
                                <p className="text-ink-muted mt-3 text-xs leading-5">
                                    Waived collections require a reason. OR
                                    sequence rules are not configured; only OR
                                    uniqueness is enforced.
                                </p>
                            </div>
                        </FinancialSection>
                    </div>

                    {createOpen ? (
                        <FinancialModal
                            title="Encode collection"
                            description="Save a collection record with OR integrity checks."
                            onClose={() => {
                                createForm.reset();
                                setCreateOpen(false);
                            }}
                        >
                            <CollectionForm
                                form={createForm}
                                onSubmit={submitCreate}
                            />
                        </FinancialModal>
                    ) : null}

                    {editing ? (
                        <FinancialModal
                            title="Edit collection"
                            description="Only pending collections can be edited."
                            onClose={() => setEditing(null)}
                        >
                            <CollectionForm
                                form={editForm}
                                onSubmit={submitEdit}
                            />
                        </FinancialModal>
                    ) : null}

                    {voiding ? (
                        <FinancialModal
                            title="Void collection"
                            description={voiding.or_number ? `OR ${voiding.or_number}` : 'Waived collection'}
                            onClose={() => setVoiding(null)}
                        >
                            <form onSubmit={submitVoid} className="space-y-4">
                                <FinancialField
                                    label="Void reason"
                                    required
                                    error={voidForm.errors.void_reason}
                                >
                                    <textarea
                                        className={inputClass}
                                        rows={3}
                                        value={voidForm.data.void_reason}
                                        onChange={(event) =>
                                            voidForm.setData(
                                                'void_reason',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FinancialField>
                                <FinancialActionButton
                                    type="submit"
                                    variant="danger"
                                    disabled={voidForm.processing}
                                >
                                    Void collection
                                </FinancialActionButton>
                            </form>
                        </FinancialModal>
                    ) : null}

                    {viewing ? (
                        <FinancialModal
                            title="Collection details"
                            description={viewing.or_number ? `OR ${viewing.or_number}` : 'Waived collection'}
                            onClose={() => setViewing(null)}
                            wide
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <FinancialMetric
                                    label="Amount"
                                    value={formatCurrency(viewing.amount)}
                                    hint="Saved collection amount."
                                    icon={CircleDollarSign}
                                />
                                <FinancialMetric
                                    label="Status"
                                    value={viewing.status}
                                    hint="Current persisted status."
                                    icon={ShieldCheck}
                                />
                                <FinancialMetric
                                    label="Date"
                                    value={formatDate(viewing.collection_date)}
                                    hint="Collection date."
                                    icon={ClipboardCheck}
                                />
                                <FinancialMetric
                                    label="Certificate"
                                    value={
                                        viewing.certificate_reference ??
                                        'Unlinked'
                                    }
                                    hint="Linked certificate request."
                                    icon={Receipt}
                                />
                            </div>
                            <div className="mt-5 space-y-2 text-sm">
                                <p>
                                    <span className="text-ink-strong font-semibold">
                                        Payee:
                                    </span>{' '}
                                    {viewing.payee_name}
                                </p>
                                <p>
                                    <span className="text-ink-strong font-semibold">
                                        Encoded by:
                                    </span>{' '}
                                    {viewing.encoded_by}
                                </p>
                                <p>
                                    <span className="text-ink-strong font-semibold">
                                        Verified by:
                                    </span>{' '}
                                    {viewing.verified_by ?? 'Not verified'}
                                </p>
                                {viewing.is_waived ? (
                                    <p>
                                        <span className="text-ink-strong font-semibold">
                                            Waive reason:
                                        </span>{' '}
                                        {viewing.waive_reason}
                                    </p>
                                ) : null}
                                {viewing.void_reason ? (
                                    <p>
                                        <span className="text-destructive font-semibold">
                                            Void reason:
                                        </span>{' '}
                                        {viewing.void_reason}
                                    </p>
                                ) : null}
                            </div>
                        </FinancialModal>
                    ) : null}
                </div>
            </AdminLayout>
        </>
    );
}

function CollectionForm({
    form,
    onSubmit,
}: {
    form: ReturnType<typeof useForm<CollectionFormData>>;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
    return (
        <form
            onSubmit={onSubmit}
            className="grid grid-cols-1 gap-4 sm:grid-cols-2"
        >
            <FinancialField
                label="Collection date"
                required
                error={form.errors.collection_date}
            >
                <input
                    type="date"
                    className={inputClass}
                    value={form.data.collection_date}
                    onChange={(event) =>
                        form.setData('collection_date', event.target.value)
                    }
                />
            </FinancialField>
            <FinancialField
                label="Collection type"
                required
                error={form.errors.collection_type}
            >
                <input
                    className={inputClass}
                    value={form.data.collection_type}
                    onChange={(event) =>
                        form.setData('collection_type', event.target.value)
                    }
                />
            </FinancialField>
            <FinancialField
                label="OR number"
                required={!form.data.is_waived}
                error={form.errors.or_number}
                hint="Required unless the collection is waived."
            >
                <input
                    className={inputClass}
                    value={form.data.or_number}
                    onChange={(event) =>
                        form.setData('or_number', event.target.value)
                    }
                />
            </FinancialField>
            <FinancialField
                label="Payee name"
                required
                error={form.errors.payee_name}
            >
                <input
                    className={inputClass}
                    value={form.data.payee_name}
                    onChange={(event) =>
                        form.setData('payee_name', event.target.value)
                    }
                />
            </FinancialField>
            <FinancialField label="Amount" required error={form.errors.amount}>
                <input
                    className={inputClass}
                    inputMode="decimal"
                    value={form.data.amount}
                    onChange={(event) =>
                        form.setData('amount', event.target.value)
                    }
                />
            </FinancialField>
            <label className="text-ink-strong flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={form.data.is_waived}
                    onChange={(event) => {
                        const isWaived = event.target.checked;
                        form.setData({
                            ...form.data,
                            is_waived: isWaived,
                            amount: isWaived ? '0' : form.data.amount,
                        });
                    }}
                />
                Waived collection
            </label>
            <FinancialField
                label="Waive reason"
                error={form.errors.waive_reason}
                hint="Required when waived."
                required={form.data.is_waived}
            >
                <input
                    className={inputClass}
                    required={form.data.is_waived}
                    value={form.data.waive_reason}
                    onChange={(event) =>
                        form.setData('waive_reason', event.target.value)
                    }
                />
            </FinancialField>
            <div className="sm:col-span-2">
                <FinancialActionButton type="submit" disabled={form.processing}>
                    Save collection
                </FinancialActionButton>
            </div>
        </form>
    );
}
