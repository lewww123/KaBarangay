import { Head, Link } from '@inertiajs/react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type Metrics = {
    filed: number;
    mediation: number;
    conciliation: number;
    settled: number;
    certificatesIssued: number;
};

type Deadline = {
    id: number;
    caseNumber: string;
    title: string;
    phase: string;
    dueDate: string;
    overdue: boolean;
};

type Hearing = {
    id: number;
    caseId: number;
    caseNumber: string | null;
    caseTitle: string | null;
    phase: string;
    date: string;
    time: string | null;
    venue: string;
};

interface Props {
    auth: { user: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    userName?: string;
    barangayName?: string | null;
    metrics: Metrics;
    deadlines: Deadline[];
    schedule: Hearing[];
}

export default withDeferredPage(LuponDashboard, ['metrics', 'deadlines', 'schedule'], (props) => (
    <LuponLayout active="dashboard" user={props.auth.user} barangayName={props.barangayName}>
        <PortalPageSkeleton metrics={5} list />
    </LuponLayout>
));

function LuponDashboard({ auth, userName, barangayName = null, metrics, deadlines, schedule }: Props) {
    const cards = [
        ['Total Cases Filed', metrics.filed, 'All recorded KP disputes'],
        ['Active Mediation', metrics.mediation, 'Punong Barangay level'],
        ['Pangkat Conciliation', metrics.conciliation, 'Active panel proceedings'],
        ['Settled Cases', metrics.settled, 'Resolution records'],
        ['Certificates Issued', metrics.certificatesIssued, 'KP document issuances'],
    ];

    return (
        <LuponLayout active="dashboard" user={auth.user} barangayName={barangayName}>
            <Head title="Lupon Dashboard" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col justify-between gap-4 rounded-2xl bg-[#315b30] p-6 text-white shadow-sm sm:flex-row sm:items-end">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-white/70">Katarungang Pambarangay</p>
                        <h1 className="mt-2 text-2xl font-bold">Case Desk Overview</h1>
                        <p className="mt-1 text-sm text-white/75">{userName ? `Signed in as ${userName}` : 'Lupon staff portal'}</p>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/lupon/complaints" className="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-[#315b30]">Review intake</Link>
                        <Link href="/lupon/cases/create" className="rounded-lg border border-white/40 px-4 py-2 text-sm font-semibold text-white">Log walk-in case</Link>
                    </div>
                </div>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.map(([label, value, hint]) => (
                        <div key={label} className="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-wide text-stone-500">{label}</p>
                            <p className="mt-2 text-3xl font-bold text-stone-900">{value}</p>
                            <p className="mt-1 text-xs text-stone-500">{hint}</p>
                        </div>
                    ))}
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="font-bold text-stone-900">Urgent attention</h2>
                                <p className="mt-1 text-sm text-stone-500">15-day mediation and conciliation checkpoints.</p>
                            </div>
                            <Link href="/lupon/cases" className="text-sm font-semibold text-[#3f6f3d]">All cases</Link>
                        </div>
                        <div className="mt-4 divide-y divide-stone-100">
                            {deadlines.length === 0 ? <p className="py-5 text-sm text-stone-500">No active cases approaching a 15-day checkpoint.</p> : deadlines.map((deadline) => (
                                <Link key={deadline.id} href={`/lupon/cases/${deadline.id}`} className="flex items-center justify-between gap-3 py-3">
                                    <div className="min-w-0"><p className="font-semibold text-stone-800">{deadline.caseNumber}</p><p className="truncate text-sm text-stone-500">{deadline.title}</p></div>
                                    <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${deadline.overdue ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'}`}>{deadline.overdue ? 'Overdue' : `Due ${deadline.dueDate}`}</span>
                                </Link>
                            ))}
                        </div>
                    </section>

                    <section className="rounded-2xl border border-stone-200 bg-white p-5 shadow-sm">
                        <div className="flex items-center justify-between"><div><h2 className="font-bold text-stone-900">This week&apos;s hearings</h2><p className="mt-1 text-sm text-stone-500">Scheduled summons and proceedings.</p></div><Link href="/lupon/hearings" className="text-sm font-semibold text-[#3f6f3d]">Calendar</Link></div>
                        <div className="mt-4 divide-y divide-stone-100">
                            {schedule.length === 0 ? <p className="py-5 text-sm text-stone-500">No hearings scheduled for the next seven days.</p> : schedule.map((hearing) => (
                                <Link key={hearing.id} href={`/lupon/cases/${hearing.caseId}`} className="flex items-center justify-between gap-3 py-3"><div><p className="font-semibold text-stone-800">{hearing.caseNumber}</p><p className="text-sm text-stone-500">{hearing.caseTitle}</p></div><div className="text-right text-xs text-stone-600"><p className="font-semibold">{hearing.date}{hearing.time ? `, ${hearing.time}` : ''}</p><p>{hearing.phase} · {hearing.venue}</p></div></Link>
                            ))}
                        </div>
                    </section>
                </div>
            </div>
        </LuponLayout>
    );
}
