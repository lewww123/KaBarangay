import { luponStatusLabel } from '@/components/lupon/types';
import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type Hearing = {
    id: number;
    caseId: number;
    caseNumber: string | null;
    caseTitle: string | null;
    caseStatus: string | null;
    hearingNumber: number;
    phase: string;
    date: string;
    time?: string | null;
    venue: string;
    status: string;
    outcome?: string | null;
};

type Props = {
    auth: {
        user: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
    hearings: {
        data: Hearing[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        total: number;
    };
    filters: { search: string; phase: string; status: string; outcome: string };
    phases: string[];
    statuses: string[];
    outcomes: string[];
};

export default withDeferredPage(HearingsIndex, ['hearings'], (props) => (
    <LuponLayout active="hearings" user={props.auth.user}>
        <PortalPageSkeleton />
    </LuponLayout>
));

function HearingsIndex({
    auth,
    hearings,
    filters,
    phases,
    statuses,
    outcomes,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [phase, setPhase] = useState(filters.phase);
    const [status, setStatus] = useState(filters.status);
    const [outcome, setOutcome] = useState(filters.outcome);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(
            '/lupon/hearings',
            { search, phase, status, outcome },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['hearings', 'filters'],
            },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setPhase('');
        setStatus('');
        setOutcome('');
        router.get(
            '/lupon/hearings',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['hearings', 'filters'],
            },
        );
    };

    return (
        <LuponLayout active="hearings" user={auth.user}>
            <Head title="Lupon Hearings" />

            <div className="mx-auto max-w-7xl space-y-6 p-2 font-sans sm:p-4">
                <header className="flex flex-col gap-4 rounded-xl border border-line-soft bg-white p-6 shadow-card sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-brand uppercase">
                            Lupon Desk
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold text-ink-strong">
                            Hearings
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Review hearing schedules and recorded outcomes for
                            your barangay.
                        </p>
                    </div>
                    <Link
                        href="/lupon/cases"
                        className="inline-flex items-center justify-center rounded-xl border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-canvas"
                    >
                        View cases
                    </Link>
                </header>

                <section
                    className="rounded-xl border border-line-soft bg-white p-4 shadow-card"
                    aria-label="Filter hearings"
                >
                    <form
                        onSubmit={applyFilters}
                        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
                    >
                        <label className="sm:col-span-2 xl:col-span-1">
                            <span className="mb-1 block text-xs font-semibold text-ink-muted">
                                Search
                            </span>
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Case number or party"
                                className="w-full rounded-xl border border-line px-3 py-2.5 text-sm text-ink-strong outline-none focus:border-brand focus:ring-2 focus:ring-[#3b6fa8]/20"
                            />
                        </label>
                        <FilterSelect
                            label="Phase"
                            value={phase}
                            options={phases}
                            onChange={setPhase}
                        />
                        <FilterSelect
                            label="Hearing status"
                            value={status}
                            options={statuses}
                            onChange={setStatus}
                        />
                        <FilterSelect
                            label="Outcome"
                            value={outcome}
                            options={outcomes}
                            onChange={setOutcome}
                        />
                        <div className="flex items-end gap-2">
                            <button
                                type="submit"
                                className="flex-1 rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-deep"
                            >
                                Apply
                            </button>
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="rounded-xl border border-line px-4 py-2.5 text-sm font-semibold text-ink hover:bg-canvas"
                            >
                                Clear
                            </button>
                        </div>
                    </form>
                </section>

                <section className="space-y-3" aria-label="Hearing records">
                    <div className="flex items-center justify-between px-1">
                        <h2 className="text-sm font-semibold text-ink">
                            Hearing records
                        </h2>
                        <span className="text-xs text-ink-muted">
                            {hearings.total} total
                        </span>
                    </div>

                    {hearings.data.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-line bg-surface p-10 text-center">
                            <span className="text-3xl" aria-hidden="true">
                                
                            </span>
                            <h3 className="mt-3 text-base font-semibold text-ink-strong">
                                No hearings found
                            </h3>
                            <p className="mt-1 text-sm text-ink-muted">
                                Scheduled hearings will appear here. You can
                                schedule one from its case record.
                            </p>
                        </div>
                    ) : (
                        hearings.data.map((hearing) => (
                            <article
                                key={hearing.id}
                                className="rounded-xl border border-line-soft bg-white p-5 shadow-card"
                            >
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="rounded-lg bg-canvas px-2.5 py-1 font-mono text-sm font-semibold text-brand-deep">
                                                {hearing.caseNumber ??
                                                    'Case unavailable'}
                                            </span>
                                            <span className="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-semibold text-brand-deep">
                                                {hearing.phase} phase
                                            </span>
                                            <StatusPill
                                                value={hearing.status}
                                            />
                                            {hearing.outcome && (
                                                <StatusPill
                                                    value={hearing.outcome}
                                                />
                                            )}
                                        </div>
                                        <h3 className="mt-3 text-base font-semibold text-ink-strong">
                                            {hearing.caseTitle ??
                                                'Case details unavailable'}
                                        </h3>
                                        <div className="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                            <Info
                                                label="Hearing"
                                                value={`#${hearing.hearingNumber}`}
                                            />
                                            <Info
                                                label="Date and time"
                                                value={`${hearing.date}${hearing.time ? ` · ${hearing.time}` : ''}`}
                                            />
                                            <Info
                                                label="Venue"
                                                value={
                                                    hearing.venue ||
                                                    'Not specified'
                                                }
                                            />
                                            <Info
                                                label="Case status"
                                                value={luponStatusLabel(
                                                    hearing.caseStatus ??
                                                        'Not available',
                                                )}
                                            />
                                        </div>
                                    </div>
                                    <Link
                                        href={`/lupon/cases/${hearing.caseId}`}
                                        className="inline-flex shrink-0 items-center justify-center rounded-xl border border-brand/30 px-4 py-2 text-sm font-semibold text-brand-deep hover:bg-canvas"
                                    >
                                        Open case 
                                    </Link>
                                </div>
                            </article>
                        ))
                    )}
                </section>

                {hearings.total > 0 && (
                    <div className="flex flex-col gap-3 rounded-xl border border-line-soft bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-ink-muted">
                            Page {hearings.current_page} · {hearings.total}{' '}
                            hearings
                        </p>
                        <nav
                            aria-label="Hearing list pages"
                            className="flex flex-wrap items-center gap-1"
                        >
                            {hearings.links.map((link, index) => {
                                const label = link.label
                                    .replace('&laquo;', '<')
                                    .replace('&raquo;', '>');
                                const classes = `min-w-9 rounded-lg px-3 py-2 text-center text-sm font-medium ${link.active ? 'bg-brand text-white' : 'text-ink-muted hover:bg-canvas'} ${!link.url ? 'cursor-not-allowed opacity-40' : ''}`;
                                return link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        preserveScroll
                                        only={['hearings', 'filters']}
                                        className={classes}
                                        aria-current={
                                            link.active ? 'page' : undefined
                                        }
                                    >
                                        {label}
                                    </Link>
                                ) : (
                                    <span
                                        key={`${link.label}-${index}`}
                                        className={classes}
                                        aria-disabled="true"
                                    >
                                        {label}
                                    </span>
                                );
                            })}
                        </nav>
                    </div>
                )}
            </div>
        </LuponLayout>
    );
}

function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
}) {
    return (
        <label>
            <span className="mb-1 block text-xs font-semibold text-ink-muted">
                {label}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm text-ink outline-none focus:border-brand"
            >
                <option value="">All</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </label>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-ink-faint uppercase">
                {label}
            </p>
            <p className="mt-0.5 text-sm text-ink">{value}</p>
        </div>
    );
}

function StatusPill({ value }: { value: string }) {
    const settled = value === 'Settled';
    const warning = value === 'Scheduled' || value === 'Ongoing';
    const style = settled
        ? 'border-green-200 bg-green-50 text-green-700'
        : warning
          ? 'border-amber-200 bg-amber-50 text-amber-700'
          : 'border-line-soft bg-canvas text-ink';
    return (
        <span
            className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${style}`}
        >
            {value}
        </span>
    );
}
