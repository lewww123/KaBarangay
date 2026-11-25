import { Head, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Banknote, ShieldCheck, Wallet } from 'lucide-react';

import {
    FinancialEmptyTableRow,
    FinancialMetric,
    FinancialNotice,
    FinancialPageHeader,
    FinancialSection,
    FinancialTableShell,
} from '@/components/financial-disclosure/ui';
import ResidentLayout from '@/layouts/ResidentLayout';
import {
    formatCurrency,
    formatDate,
    type ResidentFinancialDisclosurePageProps,
} from '@/types/financialDisclosure';

const baseUrl = '/resident/financial-disclosure';

function monthLabel(month: number): string {
    return new Date(2000, month - 1, 1).toLocaleString('en-PH', {
        month: 'long',
    });
}

export default function ResidentFinancialDisclosure() {
    const {
        period,
        periods,
        summary,
        collections,
        disbursements,
        allocations,
        attachments,
    } = usePage<ResidentFinancialDisclosurePageProps>().props;

    const [periodValue, setPeriodValue] = useState(
        `${period.year}-${String(period.month).padStart(2, '0')}`,
    );

    const applyPeriod = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const [year, month] = periodValue.split('-');

        router.get(baseUrl, { year: Number(year), month: Number(month) }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const downloadUrl = (type: string) =>
        `${baseUrl}/download/${type}?year=${period.year}&month=${period.month}`;

    return (
        <ResidentLayout active="financial-disclosure">
            <Head title="Financial Disclosure" />

            <div className="mx-auto max-w-6xl space-y-8 p-4 sm:p-6 lg:p-8">
                <FinancialPageHeader
                    eyebrow="Resident Portal / Transparency"
                    title="Financial Disclosure"
                    description="View financial information published by your barangay for the selected disclosure period."
                />

                <FinancialNotice title="Published records only" tone="info">
                    This page shows verified collections, released
                    disbursements, allocation balances, and documents that the
                    barangay has published. Collection payee names are not
                    displayed.
                </FinancialNotice>

                <section aria-labelledby="resident-financial-overview">
                    <h2
                        id="resident-financial-overview"
                        className="text-ink-strong mb-4 text-lg font-semibold"
                    >
                        {monthLabel(period.month)} {period.year}
                    </h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <FinancialMetric
                            label="Verified collections"
                            value={formatCurrency(summary.collections)}
                            hint="Excludes waived and voided records."
                            icon={Banknote}
                        />
                        <FinancialMetric
                            label="Released disbursements"
                            value={formatCurrency(summary.disbursements)}
                            hint="Released for the published period."
                            icon={Wallet}
                        />
                        <FinancialMetric
                            label="Remaining balance"
                            value={formatCurrency(summary.available_balance)}
                            hint="Approved plus signed amendments minus released disbursements."
                            icon={ShieldCheck}
                        />
                    </div>
                </section>

                <FinancialSection
                    id="published-period"
                    title="Published period"
                    description="Select a disclosure period published by your barangay."
                >
                    <form
                        onSubmit={applyPeriod}
                        className="border-line-soft flex flex-wrap items-end gap-3 rounded-xl border bg-white p-5"
                    >
                        <label className="text-ink-strong text-sm font-medium">
                            Period
                            <select
                                className="border-line-soft text-ink-strong mt-1 block rounded-lg bg-white text-sm"
                                value={periodValue}
                                onChange={(event) =>
                                    setPeriodValue(event.target.value)
                                }
                            >
                                {periods.map((item) => (
                                    <option
                                        key={item.label}
                                        value={`${item.year}-${String(item.month).padStart(2, '0')}`}
                                    >
                                        {monthLabel(item.month)} {item.year}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button
                            type="submit"
                            className="bg-brand-deep rounded-lg px-4 py-2 text-sm font-semibold text-white"
                        >
                            View period
                        </button>
                        <a
                            href={downloadUrl('collections')}
                            className="text-brand-deep text-sm font-semibold underline"
                        >
                            Download collections CSV
                        </a>
                        <a
                            href={downloadUrl('disbursements')}
                            className="text-brand-deep text-sm font-semibold underline"
                        >
                            Download disbursements CSV
                        </a>
                    </form>
                </FinancialSection>

                <FinancialSection
                    id="published-allocations"
                    title="Allocations"
                    description="Approved budgets, released amounts, and remaining balances."
                >
                    <FinancialTableShell>
                        <table className="text-ink-muted w-full min-w-[640px] text-left text-sm">
                            <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                <tr>
                                    <th className="px-5 py-3">PPA / Fund</th>
                                    <th className="px-5 py-3">Category</th>
                                    <th className="px-5 py-3">Source</th>
                                    <th className="px-5 py-3">Approved</th>
                                    <th className="px-5 py-3">Released</th>
                                    <th className="px-5 py-3">Remaining</th>
                                </tr>
                            </thead>
                            <tbody className="divide-line-soft divide-y">
                                {allocations.length === 0 ? (
                                    <FinancialEmptyTableRow
                                        colSpan={6}
                                        title="No allocations for this period"
                                        description="Published allocation records will appear here."
                                    />
                                ) : (
                                    allocations.map((allocation) => (
                                        <tr
                                            key={allocation.id}
                                            className="bg-white"
                                        >
                                            <td className="text-ink-strong px-5 py-3 font-medium">
                                                {allocation.name}
                                            </td>
                                            <td className="px-5 py-3">
                                                {allocation.category}
                                            </td>
                                            <td className="px-5 py-3">
                                                {allocation.source}
                                            </td>
                                            <td className="tnum px-5 py-3">
                                                {formatCurrency(
                                                    allocation.amount_approved,
                                                )}
                                            </td>
                                            <td className="tnum px-5 py-3">
                                                {formatCurrency(
                                                    allocation.amount_used,
                                                )}
                                            </td>
                                            <td className="tnum px-5 py-3">
                                                {formatCurrency(
                                                    allocation.remaining_balance,
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </FinancialTableShell>
                </FinancialSection>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <FinancialSection
                        id="published-collections"
                        title="Verified collections"
                        description="Published collections without payer names."
                    >
                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[420px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">Date</th>
                                        <th className="px-5 py-3">Type</th>
                                        <th className="px-5 py-3">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {collections.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={3}
                                            title="No collections for this period"
                                            description="Published collection records will appear here."
                                        />
                                    ) : (
                                        collections.map((collection) => (
                                            <tr
                                                key={collection.id}
                                                className="bg-white"
                                            >
                                                <td className="px-5 py-3">
                                                    {formatDate(
                                                        collection.collection_date,
                                                    )}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {collection.collection_type}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        collection.amount,
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </FinancialTableShell>
                    </FinancialSection>

                    <FinancialSection
                        id="published-disbursements"
                        title="Released disbursements"
                        description="Published payments released for this period."
                    >
                        <FinancialTableShell>
                            <table className="text-ink-muted w-full min-w-[520px] text-left text-sm">
                                <thead className="bg-canvas text-ink-muted text-xs font-semibold tracking-wide uppercase">
                                    <tr>
                                        <th className="px-5 py-3">Date</th>
                                        <th className="px-5 py-3">Allocation</th>
                                        <th className="px-5 py-3">Purpose</th>
                                        <th className="px-5 py-3">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-line-soft divide-y">
                                    {disbursements.length === 0 ? (
                                        <FinancialEmptyTableRow
                                            colSpan={4}
                                            title="No disbursements for this period"
                                            description="Published disbursement records will appear here."
                                        />
                                    ) : (
                                        disbursements.map((disbursement) => (
                                            <tr
                                                key={disbursement.id}
                                                className="bg-white"
                                            >
                                                <td className="px-5 py-3">
                                                    {formatDate(
                                                        disbursement.disbursement_date,
                                                    )}
                                                </td>
                                                <td className="px-5 py-3">
                                                    {
                                                        disbursement.allocation_name
                                                    }
                                                </td>
                                                <td className="px-5 py-3">
                                                    {disbursement.purpose}
                                                </td>
                                                <td className="tnum px-5 py-3">
                                                    {formatCurrency(
                                                        disbursement.amount,
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </FinancialTableShell>
                    </FinancialSection>
                </div>

                <FinancialSection
                    id="published-documents"
                    title="Supporting documents"
                    description="Documents published for this disclosure period."
                >
                    <div className="border-line-soft space-y-3 rounded-xl border bg-white p-5">
                        {attachments.length === 0 ? (
                            <p className="text-ink-muted text-sm">
                                No supporting documents are published for this
                                period.
                            </p>
                        ) : (
                            attachments.map((attachment) => (
                                <div
                                    key={attachment.id}
                                    className="border-line-soft bg-surface flex items-center justify-between gap-3 rounded-lg border px-4 py-3"
                                >
                                    <span className="text-ink-strong text-sm font-medium">
                                        {attachment.title}
                                    </span>
                                    <div className="flex items-center gap-3">
                                        <span className="text-ink-muted text-xs">
                                            {formatDate(attachment.uploaded_at)}
                                        </span>
                                        <a
                                            href={attachment.url}
                                            className="text-brand-deep text-xs font-semibold underline"
                                        >
                                            View document
                                        </a>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </FinancialSection>
            </div>
        </ResidentLayout>
    );
}
