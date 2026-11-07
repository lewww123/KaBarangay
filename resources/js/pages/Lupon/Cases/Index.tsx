import { luponStatusLabel, luponStatusClasses } from '@/components/lupon/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type CaseItem = {
    id: number;
    caseNumber: string;
    title: string;
    caseType: string;
    phase: string;
    status: string;
    filedDate: string;
    complainantName: string;
    respondentName: string;
    complainantsCount: number;
    respondentsCount: number;
    latestHearing?: {
        number: number;
        date: string;
        time?: string | null;
        status: string;
    } | null;
};

type Props = {
    auth: {
        user: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
    cases: {
        data: CaseItem[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search: string;
        status: string;
        phase: string;
    };
    counts: {
        total: number;
        active: number;
        settled: number;
        unresolved: number;
    };
    phases: string[];
    statuses: string[];
};

export default withDeferredPage(CasesIndex, ['cases', 'counts'], (props) => (
    <LuponLayout active="cases" user={props.auth.user}>
        <PortalPageSkeleton metrics={4} />
    </LuponLayout>
));

function CasesIndex({ auth, cases, filters, counts, phases, statuses }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [phase, setPhase] = useState(filters.phase);

    const applyFilter = (newFilters: {
        search?: string;
        status?: string;
        phase?: string;
    }) => {
        router.get(
            '/lupon/cases',
            {
                search: newFilters.search ?? search,
                status: newFilters.status ?? status,
                phase: newFilters.phase ?? phase,
            },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['cases', 'filters'],
            },
        );
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilter({ search });
    };

    return (
        <LuponLayout active="cases" user={auth.user}>
            <Head title="Lupon Cases" />

            <div className="mx-auto max-w-7xl space-y-6 p-2 font-sans sm:p-4">
                {/* Header */}
                <div className="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">
                            Lupon Cases
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Manage Katarungang Pambarangay cases, mediation, and
                            conciliation proceedings.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href="/lupon/cases/create"
                            className="inline-flex items-center gap-1.5 rounded-xl bg-[#3b6fa8] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#2a5279]"
                        >
                            + Add Case
                        </Link>
                        <Link
                            href="/lupon/complaints"
                            className="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                        >
                            📋 Intake Queue
                        </Link>
                    </div>
                </div>

                {/* Metrics */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <MetricCard
                        label="Total Cases"
                        value={counts.total}
                        color="blue"
                    />
                    <MetricCard
                        label="Active / Mediation"
                        value={counts.active}
                        color="amber"
                    />
                    <MetricCard
                        label="Settled"
                        value={counts.settled}
                        color="green"
                    />
                    <MetricCard
                        label="Unresolved"
                        value={counts.unresolved}
                        color="red"
                    />
                </div>

                {/* Filter and Search Bar */}
                <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <form
                        onSubmit={handleSearch}
                        className="flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <div className="flex-1">
                            <input
                                type="text"
                                placeholder="Search by case #, title, or party name..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm text-gray-800 outline-none focus:border-[#3b6fa8] focus:ring-2 focus:ring-[#3b6fa8]/20"
                            />
                        </div>

                        <div className="flex gap-2">
                            <select
                                value={phase}
                                onChange={(e) => {
                                    setPhase(e.target.value);
                                    applyFilter({ phase: e.target.value });
                                }}
                                className="rounded-xl border border-gray-300 px-3 py-2.5 text-sm text-gray-700 outline-none focus:border-[#3b6fa8]"
                            >
                                <option value="">All Phases</option>
                                {phases.map((p) => (
                                    <option key={p} value={p}>
                                        {p} Phase
                                    </option>
                                ))}
                            </select>

                            <select
                                value={luponStatusLabel(status)}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    applyFilter({ status: e.target.value });
                                }}
                                className="rounded-xl border border-gray-300 px-3 py-2.5 text-sm text-gray-700 outline-none focus:border-[#3b6fa8]"
                            >
                                <option value="">All Statuses</option>
                                {statuses.map((s) => (
                                    <option key={s} value={s}>
                                        {luponStatusLabel(s)}
                                    </option>
                                ))}
                            </select>

                            <button
                                type="submit"
                                className="rounded-xl bg-[#3b6fa8] px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#2a5279]"
                            >
                                Filter
                            </button>
                        </div>
                    </form>
                </div>

                {/* Cases List */}
                {cases.data.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-gray-300 bg-[#ffffff] p-12 text-center">
                        <span className="text-3xl">📂</span>
                        <h3 className="mt-3 text-base font-semibold text-gray-800">
                            No cases found
                        </h3>
                        <p className="mt-1 text-sm text-gray-500">
                            {filters.search || filters.status || filters.phase
                                ? 'No case records matched your active filter criteria.'
                                : 'No complaints have been promoted to a case yet. Review accepted complaints in the intake queue.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {cases.data.map((c) => (
                            <div
                                key={c.id}
                                className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-[#3b6fa8]/60"
                            >
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="space-y-1.5">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-lg font-bold text-gray-900">
                                                {c.caseNumber}
                                            </span>
                                            <span className="rounded-full bg-[#eaf1f8] px-2.5 py-0.5 text-xs font-semibold text-[#3b6fa8]">
                                                {c.phase} Phase
                                            </span>
                                            <StatusBadge status={c.status} />
                                        </div>

                                        <p className="text-sm font-semibold text-gray-800">
                                            {c.title}
                                        </p>

                                        <div className="mt-2 grid gap-1.5 text-xs text-gray-500 sm:grid-cols-2 sm:gap-x-8">
                                            <p>
                                                <span className="text-gray-400">
                                                    Complainant:
                                                </span>{' '}
                                                {c.complainantName}
                                                {c.complainantsCount > 1 &&
                                                    ` (+${c.complainantsCount - 1} more)`}
                                            </p>
                                            <p>
                                                <span className="text-gray-400">
                                                    Respondent:
                                                </span>{' '}
                                                {c.respondentName}
                                                {c.respondentsCount > 1 &&
                                                    ` (+${c.respondentsCount - 1} more)`}
                                            </p>
                                            <p>
                                                <span className="text-gray-400">
                                                    Filed Date:
                                                </span>{' '}
                                                {c.filedDate}
                                            </p>
                                            {c.latestHearing && (
                                                <p>
                                                    <span className="text-gray-400">
                                                        Latest Hearing:
                                                    </span>{' '}
                                                    #{c.latestHearing.number} on{' '}
                                                    {c.latestHearing.date} (
                                                    {c.latestHearing.status})
                                                </p>
                                            )}
                                        </div>
                                    </div>

                                    <Link
                                        href={`/lupon/cases/${c.id}`}
                                        className="shrink-0 rounded-xl bg-[#3b6fa8] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#2a5279]"
                                    >
                                        View Case Details →
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {cases.total > 0 && (
                    <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-gray-500">
                            Showing{' '}
                            {(cases.current_page - 1) * cases.per_page + 1}–
                            {Math.min(
                                cases.current_page * cases.per_page,
                                cases.total,
                            )}{' '}
                            of {cases.total} cases
                        </p>
                        <nav
                            aria-label="Case list pages"
                            className="flex flex-wrap items-center gap-1"
                        >
                            {cases.links.map((link, index) => {
                                const label = link.label
                                    .replace('&laquo;', '‹')
                                    .replace('&raquo;', '›');
                                const classes = `min-w-9 rounded-lg px-3 py-2 text-center text-sm font-medium ${link.active ? 'bg-[#3b6fa8] text-white' : 'text-gray-600 hover:bg-[#eef1f5]'} ${!link.url ? 'cursor-not-allowed opacity-40' : ''}`;

                                return link.url ? (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url}
                                        preserveScroll
                                        only={['cases', 'filters']}
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

function MetricCard({
    label,
    value,
    color,
}: {
    label: string;
    value: number;
    color: string;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <p className="text-xs font-semibold tracking-wide text-gray-500 uppercase">
                {label}
            </p>
            <p
                className={`mt-1 text-2xl font-bold ${{ green: 'text-emerald-800', amber: 'text-amber-800', red: 'text-red-800' }[color] ?? 'text-slate-900'}`}
            >
                {value}
            </p>
        </div>
    );
}

function StatusBadge({ status }: { status: string }) {
    const classes = luponStatusClasses(status);

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${classes}`}
        >
            {luponStatusLabel(status)}
        </span>
    );
}
