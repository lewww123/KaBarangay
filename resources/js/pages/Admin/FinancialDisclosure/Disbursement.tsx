import { Head, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    BadgeCheck,
    Ban,
    Banknote,
    CheckCircle2,
    FilePlus2,
    Landmark,
    Pencil,
    ShieldCheck,
    Wallet,
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
    FinancialDetailList,
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
    type DisbursementPageProps,
    type DisbursementRecord,
} from '@/types/financialDisclosure';

const baseUrl = '/admin/financial-disclosure/disbursements';

type DisbursementFormData = {
    disbursement_date: string;
    allocation_id: string;
    payee: string;
    purpose: string;
    amount: string;
    dv_number: string;
};

type FilterForm = {
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

type DisbursementDetails = {
    disbursement: DisbursementRecord;
    balance: {
        approved: number;
        amendments: number;
        available: number;
        used: number;
        remaining: number;
    };
};

function statusTone(status: string) {
    if (status === 'Released') return 'success' as const;
    if (status === 'Approved') return 'info' as const;
    if (status === 'Voided') return 'danger' as const;

    return 'warning' as const;
}

const inputClass =
    'block w-full rounded-lg border-line-soft bg-white text-sm text-ink-strong placeholder:text-ink-faint';

export default function Disbursement() {
    const {
        disbursements,
        summary,
        allocationOptions,
        filters,
        statuses,
        flash,
    } = usePage<DisbursementPageProps>().props;

    const [filterForm, setFilterForm] = useState<FilterForm>({ ...filters });
    const [busy, setBusy] = useState(false);
    const [navigationError, setNavigationError] = useState<string | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<DisbursementRecord | null>(null);
    const [viewing, setViewing] = useState<DisbursementDetails | null>(null);
    const [detailsError, setDetailsError] = useState<string | null>(null);
    const [detailsLoading, setDetailsLoading] = useState(false);
    const [voiding, setVoiding] = useState<DisbursementRecord | null>(null);

    const emptyForm: DisbursementFormData = {
        disbursement_date: new Date().toISOString().slice(0, 10),
        allocation_id: '',
        payee: '',
        purpose: '',
        amount: '',
        dv_number: '',
    };

    const createForm = useForm<DisbursementFormData>({ ...emptyForm });
    const editForm = useForm<DisbursementFormData>({ ...emptyForm });
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
                        'The disbursement list could not be filtered.',
                    ),
            },
        );
    };

    const clearFilters = () => {
        const empty: FilterForm = {
            search: '',
            payee: '',
            purpose: '',
            dv_number: '',
            date_from: '',
            date_to: '',
            status: '',
            allocation_id: '',
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

    const openCreate = () => {
        createForm.reset();
        createForm.setData('allocation_id', allocationOptions[0]?.id ?? '');
        setCreateOpen(true);
    };

    const openEdit = (record: DisbursementRecord) => {
        setEditing(record);
        editForm.setData({
            disbursement_date: record.disbursement_date ?? '',
            allocation_id: record.allocation_id,
            payee: record.payee,
            purpose: record.purpose,
            amount: String(record.amount),
            dv_number: record.dv_number ?? '',
        });
    };

    const view = async (record: DisbursementRecord) => {
        setDetailsError(null);
        setViewing(null);
        setDetailsLoading(true);

        try {
            const response = await axios.get<DisbursementDetails>(
                `${baseUrl}/${record.id}`,
            );
            setViewing(response.data);
        } catch {
            setDetailsError('The disbursement details could not be loaded.');
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
            onSuccess: () => setEditing(null),
        });
    };

    const transition = (
        record: DisbursementRecord,
        action: 'approve' | 'release',
    ) => {
        setNavigationError(null);
        router.post(
            `${baseUrl}/${record.id}/${action}`,
            {},
            {
                preserveScroll: true,
                onError: () =>
                    setNavigationError(
                        `The disbursement could not be ${action}d.`,
                    ),
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

    const rows = Array.isArray(disbursements.data) ? disbursements.data : [];

    return (
        <>
            <Head title="Financial Disclosure - Disbursement" />
            <AdminLayout active="financial-disclosure-disbursement">
                <div className="mx-auto max-w-7xl space-y-8 p-4 sm:p-6 lg:p-8">
                    <FinancialPageHeader
                        eyebrow="Financial Disclosure / Admin"
                        title="Disbursement — Outgoing Funds"
                        description="Create and manage database-backed disbursement drafts with allocation balance checks, approval, release, voiding, attribution, and audit history."
                    />

                    <FinancialAlert
                        success={flash?.success}
                        error={navigationError ?? detailsError ?? flash?.error}
                    />

                    <FinancialNotice
                        title="Balance enforcement is active"
                        tone="warning"
                    >
                        A disbursement cannot exceed the remaining allocation
                        balance. Only released disbursements reduce the balance;
                        signed amendments adjust the approved amount.
                    </FinancialNotice>

                    <section aria-labelledby="disbursement-overview">
                        <h2
                            id="disbursement-overview"
                            className="text-ink-strong mb-4 text-lg font-semibold"
                        >
                            Disbursement overview
                        </h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FinancialMetric
                                label="Released"
                                value={formatCurrency(summary.released_amount)}
                                hint="Released, non-voided records."
                                icon={Banknote}
                            />
                            <FinancialMetric
                                label="Draft"
                                value={String(summary.draft_count)}
                                hint="Editable disbursement drafts."
                                icon={FilePlus2}
                            />
                            <FinancialMetric
                                label="Approved"
                                value={String(summary.approved_count)}
                                hint="Awaiting release."
                                icon={BadgeCheck}
                            />
                            <FinancialMetric
                                label="Voided"
                                value={String(summary.voided_count)}
                                hint="Preserved with void reason."
                                icon={Ban}
                            />
                        </div>
                    </section>

                    <FinancialSection
                        id="disbursement-records"
                        title="Disbursement records"
                        description="Search, filter, sort, and page through saved disbursements."
                    >
                        <div className="flex justify-end">
                            <FinancialActionButton
                                onClick={openCreate}
                                disabled={allocationOptions.length === 0}
                            >
                                <FilePlus2 className="size-4" />
                                Create disbursement
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
                                    placeholder="Payee, purpose, DV"
                                />
                            </FinancialField>
                            <FinancialField label="Payee">
                                <input
                                    className={inputClass}
                                    value={filterForm.payee}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            payee: event.target.value,
                                        })
                                    }
                                />
                            </FinancialField>
                            <FinancialField label="Purpose">
                                <input
                                    className={inputClass}
                                    value={filterForm.purpose}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            purpose: event.target.value,
                                        })
                                    }
                                />
                            </FinancialField>
                            <FinancialField label="DV number">
                                <input
                                    className={inputClass}
                                    value={filterForm.dv_number}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            dv_number: event.target.value,
                                        })
                                    }
                                />
                            </FinancialField>
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
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {status}
                                        </option>
                                    ))}
                                </select>
                            </FinancialField>
                            <FinancialField label="Allocation">
                                <select
                                    className={inputClass}
                                    value={filterForm.allocation_id}
                                    onChange={(event) =>
                                        setFilterForm({
                                            ...filterForm,
                                            allocation_id: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">All allocations</option>
                                    {allocationOptions.map((allocation) => (
                                        <option
                                            key={allocation.id}
                                            value={allocation.id}
                                        >
                                            {allocation.label}
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
                                </select>
                            </FinancialField>
                        </FinancialFilterBar>

                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[1240px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">Date</th>
                                        <th className="px-5 py-3">DV Number</th>
                                        <th className="px-5 py-3">Payee</th>
                                        <th className="px-5 py-3">Purpose</th>
                                        <th className="px-5 py-3">
                                            Allocation
                                        </th>
                                        <th className="px-5 py-3">Amount</th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">
                                            Attribution
                                        </th>
                                        <th className="px-5 py-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {rows.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={9}
                                            title="No disbursement records found"
                                            description="Create a disbursement draft or adjust the active filters."
                                        />
                                    ) : (
                                        rows.map((record) => (
                                            <tr
                                                key={record.id}
                                                className="bg-white"
                                            >
                                                <td className="px-5 py-3">
                                                    {formatDate(
                                                        record.disbursement_date,
                                                    )}
                                                </td>
                                                <td className="text-ink-strong px-5 py-3 font-medium">
                                                    {record.dv_number ?? '—'}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.payee}
                                                </td>
                                                <td
                                                    className="max-w-[16rem] truncate px-5 py-3"
                                                    title={record.purpose}
                                                >
                                                    {record.purpose}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {record.fiscal_year} —{' '}
                                                    {record.allocation_name}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        record.amount,
                                                    )}
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
                                                    <span className="block text-xs">
                                                        Prepared:{' '}
                                                        {record.prepared_by}
                                                    </span>
                                                    <span className="block text-xs">
                                                        Approved:{' '}
                                                        {record.approved_by ??
                                                            '—'}
                                                    </span>
                                                    <span className="block text-xs">
                                                        Released:{' '}
                                                        {record.released_by ??
                                                            '—'}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                view(record)
                                                            }
                                                            disabled={
                                                                detailsLoading
                                                            }
                                                            className="text-brand-deep text-xs font-semibold underline disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            View
                                                        </button>
                                                        {record.status ===
                                                        'Draft' ? (
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
                                                                        transition(
                                                                            record,
                                                                            'approve',
                                                                        )
                                                                    }
                                                                    className="text-brand-deep inline-flex items-center gap-1 text-xs font-semibold underline"
                                                                >
                                                                    <BadgeCheck className="size-3" />{' '}
                                                                    Approve
                                                                </button>
                                                            </>
                                                        ) : null}
                                                        {record.status ===
                                                        'Approved' ? (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    transition(
                                                                        record,
                                                                        'release',
                                                                    )
                                                                }
                                                                className="text-brand-deep inline-flex items-center gap-1 text-xs font-semibold underline"
                                                            >
                                                                <CheckCircle2 className="size-3" />{' '}
                                                                Release
                                                            </button>
                                                        ) : null}
                                                        {record.status !==
                                                        'Voided' ? (
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
                            paginator={disbursements}
                            label="Disbursement"
                        />
                    </FinancialSection>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <FinancialSection
                            id="disbursement-status-flow"
                            title="Status workflow"
                            description="Statuses persisted for disbursement records."
                        >
                            <div className="border-line-soft shadow-card rounded-xl border bg-white p-5">
                                <FinancialStatusFlow
                                    steps={['Draft', 'Approved', 'Released']}
                                />
                                <p className="text-ink-muted mt-4 text-xs leading-5">
                                    Voiding is retained as terminal financial
                                    history and requires a reason.
                                </p>
                            </div>
                        </FinancialSection>

                        <FinancialSection
                            id="allocation-balance-note"
                            title="Allocation balance"
                            description="Server-side safeguard applied on create, update, approve, and release."
                        >
                            <div className="border-brand-muted bg-brand-soft rounded-xl border p-5">
                                <div className="flex items-start gap-3">
                                    <span className="text-brand-deep flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/70">
                                        <Wallet className="size-[18px]" />
                                    </span>
                                    <div>
                                        <p className="text-brand-deep text-sm font-semibold">
                                            Remaining balance
                                        </p>
                                        <p className="text-brand-deep mt-1 text-sm leading-6">
                                            Approved amount + positive
                                            amendments − non-voided
                                            disbursements.
                                        </p>
                                        <p className="text-brand-deep/80 mt-2 text-xs leading-5">
                                            Balance is checked inside a locked
                                            database transaction.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </FinancialSection>
                    </div>

                    <FinancialSection
                        id="disbursement-personnel"
                        title="Responsible personnel"
                        description="Attribution captured by the saved workflow."
                    >
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                            {[
                                {
                                    label: 'Prepared By',
                                    icon: FilePlus2,
                                    hint: 'Set when the draft is created.',
                                },
                                {
                                    label: 'Approved By',
                                    icon: ShieldCheck,
                                    hint: 'Set when the draft is approved.',
                                },
                                {
                                    label: 'Released By',
                                    icon: Landmark,
                                    hint: 'Set when the approved record is released.',
                                },
                            ].map((item) => (
                                <div
                                    key={item.label}
                                    className="border-line-soft shadow-card rounded-xl border bg-white p-5"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <p className="text-ink-strong text-sm font-semibold">
                                            {item.label}
                                        </p>
                                        <item.icon className="text-brand-deep size-5" />
                                    </div>
                                    <p className="text-ink-muted mt-4 text-xs leading-5">
                                        {item.hint}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </FinancialSection>

                    {createOpen ? (
                        <FinancialModal
                            title="Create disbursement"
                            description="Save a draft with allocation balance validation."
                            onClose={() => {
                                createForm.reset();
                                setCreateOpen(false);
                            }}
                        >
                            <DisbursementForm
                                form={createForm}
                                allocations={allocationOptions}
                                onSubmit={submitCreate}
                                disableAllocation={false}
                            />
                        </FinancialModal>
                    ) : null}

                    {editing ? (
                        <FinancialModal
                            title="Edit disbursement"
                            description="Only draft disbursements can be edited."
                            onClose={() => setEditing(null)}
                        >
                            <DisbursementForm
                                form={editForm}
                                allocations={allocationOptions}
                                onSubmit={submitEdit}
                                disableAllocation
                            />
                        </FinancialModal>
                    ) : null}

                    {voiding ? (
                        <FinancialModal
                            title="Void disbursement"
                            description={`${voiding.dv_number ?? voiding.payee} — ${formatCurrency(voiding.amount)}`}
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
                                    Void disbursement
                                </FinancialActionButton>
                            </form>
                        </FinancialModal>
                    ) : null}

                    {detailsLoading && !viewing ? (
                        <FinancialModal
                            title="Disbursement details"
                            description="Loading the saved disbursement record."
                            onClose={() => setDetailsLoading(false)}
                            wide
                        >
                            <p className="text-ink-muted text-sm">
                                Loading disbursement details…
                            </p>
                        </FinancialModal>
                    ) : null}

                    {viewing ? (
                        <FinancialModal
                            title="Disbursement details"
                            description={`${viewing.disbursement.dv_number ?? viewing.disbursement.payee} — ${viewing.disbursement.status}`}
                            onClose={() => setViewing(null)}
                            wide
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <FinancialMetric
                                    label="Amount"
                                    value={formatCurrency(
                                        viewing.disbursement.amount,
                                    )}
                                    hint="Saved disbursement amount."
                                    icon={Banknote}
                                />
                                <FinancialMetric
                                    label="Remaining"
                                    value={formatCurrency(
                                        viewing.balance.remaining,
                                    )}
                                    hint="Allocation balance excluding this record."
                                    icon={Wallet}
                                />
                                <FinancialMetric
                                    label="Available"
                                    value={formatCurrency(
                                        viewing.balance.available,
                                    )}
                                    hint="Approved plus positive amendments."
                                    icon={ShieldCheck}
                                />
                                <FinancialMetric
                                    label="Used"
                                    value={formatCurrency(viewing.balance.used)}
                                    hint="Non-voided disbursements."
                                    icon={Landmark}
                                />
                            </div>
                            <div className="mt-5">
                                <FinancialDetailList
                                    items={[
                                        {
                                            label: 'Allocation',
                                            value: `${viewing.disbursement.fiscal_year} — ${viewing.disbursement.allocation_name}`,
                                        },
                                        {
                                            label: 'Payee',
                                            value: viewing.disbursement.payee,
                                        },
                                        {
                                            label: 'Purpose',
                                            value: viewing.disbursement.purpose,
                                        },
                                        {
                                            label: 'Date',
                                            value: formatDate(
                                                viewing.disbursement
                                                    .disbursement_date,
                                            ),
                                        },
                                        {
                                            label: 'Prepared by',
                                            value: viewing.disbursement
                                                .prepared_by,
                                        },
                                        {
                                            label: 'Approved by',
                                            value:
                                                viewing.disbursement
                                                    .approved_by ??
                                                'Not approved',
                                        },
                                        {
                                            label: 'Released by',
                                            value:
                                                viewing.disbursement
                                                    .released_by ??
                                                'Not released',
                                        },
                                        {
                                            label: 'Status',
                                            value: viewing.disbursement.status,
                                        },
                                    ]}
                                />
                            </div>
                            {viewing.disbursement.void_reason ? (
                                <p className="text-destructive mt-4 text-sm">
                                    Void reason:{' '}
                                    {viewing.disbursement.void_reason}
                                </p>
                            ) : null}
                        </FinancialModal>
                    ) : null}
                </div>
            </AdminLayout>
        </>
    );
}

function DisbursementForm({
    form,
    allocations,
    onSubmit,
    disableAllocation = false,
}: {
    form: ReturnType<typeof useForm<DisbursementFormData>>;
    allocations: Array<{ id: string; label: string }>;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    disableAllocation?: boolean;
}) {
    return (
        <form
            onSubmit={onSubmit}
            className="grid grid-cols-1 gap-4 sm:grid-cols-2"
        >
            <FinancialField
                label="Disbursement date"
                required
                error={form.errors.disbursement_date}
            >
                <input
                    type="date"
                    className={inputClass}
                    value={form.data.disbursement_date}
                    onChange={(event) =>
                        form.setData('disbursement_date', event.target.value)
                    }
                />
            </FinancialField>
            <FinancialField
                label="Allocation"
                required
                error={form.errors.allocation_id}
            >
                <select
                    className={inputClass}
                    value={form.data.allocation_id}
                    disabled={disableAllocation}
                    onChange={(event) =>
                        form.setData('allocation_id', event.target.value)
                    }
                >
                    <option value="">Select allocation</option>
                    {allocations.map((allocation) => (
                        <option key={allocation.id} value={allocation.id}>
                            {allocation.label}
                        </option>
                    ))}
                </select>
            </FinancialField>
            <FinancialField label="Payee" required error={form.errors.payee}>
                <input
                    className={inputClass}
                    value={form.data.payee}
                    onChange={(event) =>
                        form.setData('payee', event.target.value)
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
            <div className="sm:col-span-2">
                <FinancialField
                    label="Purpose"
                    required
                    error={form.errors.purpose}
                >
                    <textarea
                        className={inputClass}
                        rows={3}
                        value={form.data.purpose}
                        onChange={(event) =>
                            form.setData('purpose', event.target.value)
                        }
                    />
                </FinancialField>
            </div>
            <FinancialField
                label="DV / check number"
                error={form.errors.dv_number}
            >
                <input
                    className={inputClass}
                    value={form.data.dv_number}
                    onChange={(event) =>
                        form.setData('dv_number', event.target.value)
                    }
                />
            </FinancialField>
            <div className="flex items-end sm:col-span-1">
                <FinancialActionButton type="submit" disabled={form.processing}>
                    Save disbursement
                </FinancialActionButton>
            </div>
        </form>
    );
}
