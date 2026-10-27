import { Head, Link, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';

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
    auth: { user: { name?: string; staff_id?: string; department?: string } };
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

export default function HearingsIndex({ auth, hearings, filters, phases, statuses, outcomes }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [phase, setPhase] = useState(filters.phase);
    const [status, setStatus] = useState(filters.status);
    const [outcome, setOutcome] = useState(filters.outcome);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get('/lupon/hearings', { search, phase, status, outcome }, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setSearch('');
        setPhase('');
        setStatus('');
        setOutcome('');
        router.get('/lupon/hearings', {}, { preserveState: true, preserveScroll: true });
    };

    return (
        <LuponLayout active="hearings" user={auth.user}>
            <Head title="Lupon Hearings" />

            <div className="mx-auto max-w-7xl space-y-6 p-2 font-sans sm:p-4">
                <header className="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-[#3f6f3d]">Lupon Desk</p>
                        <h1 className="mt-1 text-2xl font-semibold text-gray-800">Hearings</h1>
                        <p className="mt-1 text-sm text-gray-500">Review hearing schedules and recorded outcomes for your barangay.</p>
                    </div>
                    <Link href="/lupon/cases" className="inline-flex items-center justify-center rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        View cases
                    </Link>
                </header>

                <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" aria-label="Filter hearings">
                    <form onSubmit={applyFilters} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <label className="sm:col-span-2 xl:col-span-1">
                            <span className="mb-1 block text-xs font-semibold text-gray-600">Search</span>
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Case number or party"
                                className="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm text-gray-800 outline-none focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/20"
                            />
                        </label>
                        <FilterSelect label="Phase" value={phase} options={phases} onChange={setPhase} />
                        <FilterSelect label="Hearing status" value={status} options={statuses} onChange={setStatus} />
                        <FilterSelect label="Outcome" value={outcome} options={outcomes} onChange={setOutcome} />
                        <div className="flex items-end gap-2">
                            <button type="submit" className="flex-1 rounded-xl bg-[#3f6f3d] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#315b30]">Apply</button>
                            <button type="button" onClick={clearFilters} className="rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Clear</button>
                        </div>
                    </form>
                </section>

                <section className="space-y-3" aria-label="Hearing records">
                    <div className="flex items-center justify-between px-1">
                        <h2 className="text-sm font-semibold text-gray-700">Hearing records</h2>
                        <span className="text-xs text-gray-500">{hearings.total} total</span>
                    </div>

                    {hearings.data.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-gray-300 bg-[#fafaf7] p-10 text-center">
                            <span className="text-3xl" aria-hidden="true">🗓️</span>
                            <h3 className="mt-3 text-base font-semibold text-gray-800">No hearings found</h3>
                            <p className="mt-1 text-sm text-gray-500">Scheduled hearings will appear here. You can schedule one from its case record.</p>
                        </div>
                    ) : hearings.data.map((hearing) => (
                        <article key={hearing.id} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-lg bg-[#f4f7ef] px-2.5 py-1 font-mono text-sm font-bold text-[#315b30]">
                                            {hearing.caseNumber ?? 'Case unavailable'}
                                        </span>
                                        <span className="rounded-full bg-[#e8f1e4] px-2.5 py-1 text-xs font-semibold text-[#315b30]">{hearing.phase} phase</span>
                                        <StatusPill value={hearing.status} />
                                        {hearing.outcome && <StatusPill value={hearing.outcome} />}
                                    </div>
                                    <h3 className="mt-3 text-base font-semibold text-gray-900">{hearing.caseTitle ?? 'Case details unavailable'}</h3>
                                    <div className="mt-3 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                        <Info label="Hearing" value={`#${hearing.hearingNumber}`} />
                                        <Info label="Date and time" value={`${hearing.date}${hearing.time ? ` · ${hearing.time}` : ''}`} />
                                        <Info label="Venue" value={hearing.venue || 'Not specified'} />
                                        <Info label="Case status" value={hearing.caseStatus ?? 'Not available'} />
                                    </div>
                                </div>
                                <Link href={`/lupon/cases/${hearing.caseId}`} className="inline-flex shrink-0 items-center justify-center rounded-xl border border-[#3f6f3d]/30 px-4 py-2 text-sm font-semibold text-[#315b30] hover:bg-[#f4f7ef]">
                                    Open case →
                                </Link>
                            </div>
                        </article>
                    ))}
                </section>

                {hearings.total > 0 && (
                    <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-gray-500">Page {hearings.current_page} · {hearings.total} hearings</p>
                        <nav aria-label="Hearing list pages" className="flex flex-wrap items-center gap-1">
                            {hearings.links.map((link, index) => {
                                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                                const classes = `min-w-9 rounded-lg px-3 py-2 text-center text-sm font-medium ${link.active ? 'bg-[#3f6f3d] text-white' : 'text-gray-600 hover:bg-[#f4f7ef]'} ${!link.url ? 'cursor-not-allowed opacity-40' : ''}`;
                                return link.url ? (
                                    <Link key={`${link.label}-${index}`} href={link.url} preserveScroll className={classes} aria-current={link.active ? 'page' : undefined}>{label}</Link>
                                ) : (
                                    <span key={`${link.label}-${index}`} className={classes} aria-disabled="true">{label}</span>
                                );
                            })}
                        </nav>
                    </div>
                )}
            </div>
        </LuponLayout>
    );
}

function FilterSelect({ label, value, options, onChange }: { label: string; value: string; options: string[]; onChange: (value: string) => void }) {
    return (
        <label>
            <span className="mb-1 block text-xs font-semibold text-gray-600">{label}</span>
            <select value={value} onChange={(event) => onChange(event.target.value)} className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-700 outline-none focus:border-[#3f6f3d]">
                <option value="">All</option>
                {options.map((option) => <option key={option} value={option}>{option}</option>)}
            </select>
        </label>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{label}</p>
            <p className="mt-0.5 text-sm text-gray-700">{value}</p>
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
          : 'border-gray-200 bg-gray-50 text-gray-700';
    return <span className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${style}`}>{value}</span>;
}
