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
    pendingPayment?: number;
    pendingComplaints?: number;
    awaitingPayment?: number;
    activeMediation?: number;
    activeConciliation?: number;
    resolved?: number;
    arbitration?: number;
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

type RecentActivity = {
    id: number;
    caseId: number;
    caseNumber: string | null;
    caseTitle: string | null;
    fromStatus: string | null;
    toStatus: string;
    label: string;
    notes: string | null;
    occurredAt: string | null;
};

interface Props {
    auth: {
        user: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
    userName?: string;
    barangayName?: string | null;
    metrics: Metrics;
    deadlines: Deadline[];
    schedule: Hearing[];
    recentActivity: RecentActivity[];
}

export default withDeferredPage(
    LuponDashboard,
    ['metrics', 'deadlines', 'schedule', 'recentActivity'],
    (props) => (
        <LuponLayout
            active="overview"
            user={props.auth.user}
            barangayName={props.barangayName}
        >
            <PortalPageSkeleton metrics={4} list />
        </LuponLayout>
    ),
);

function LuponDashboard({
    auth,
    userName,
    barangayName = null,
    metrics,
    deadlines,
    schedule,
    recentActivity,
}: Props) {
    const activeProceedings =
        (metrics.activeMediation ?? metrics.mediation ?? 0) +
        (metrics.activeConciliation ?? metrics.conciliation ?? 0) +
        (metrics.arbitration ?? 0);

    const cards = [
        [
            'Total Official Cases',
            metrics.filed,
            'Docketed cases in your barangay',
        ],
        [
            'Pending Intake',
            metrics.pendingComplaints ?? 0,
            'Complaints awaiting review',
        ],
        [
            'Active Proceedings',
            activeProceedings,
            'Mediation & conciliation',
        ],
        [
            'Resolved Cases',
            metrics.resolved ?? metrics.settled,
            'Amicably settled or closed',
        ],
    ];

    return (
        <LuponLayout
            active="overview"
            user={auth.user}
            barangayName={barangayName}
        >
            <Head title="Lupon Overview" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="rounded-xl bg-[#315b30] p-6 text-white shadow-card">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.16em] text-white/70 uppercase">
                            Katarungang Pambarangay
                        </p>
                        <h1 className="mt-2 text-2xl font-semibold">
                            Case Desk Overview
                        </h1>
                        <p className="mt-1 text-sm text-white/75">
                            {userName
                                ? `Signed in as ${userName}`
                                : 'Lupon staff portal'}
                        </p>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2.5">
                        <Link
                            href="/lupon/complaints"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-[#315b30] shadow-card transition hover:bg-[#f7f6f0]"
                        >
                            Review intake
                        </Link>
                        <Link
                            href="/lupon/cases/create"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-white/40 bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/20"
                        >
                            Log walk-in case
                        </Link>
                    </div>
                </div>

                <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map(([label, value, hint]) => (
                        <div
                            key={label}
                            className="rounded-xl border border-stone-200 bg-white p-4 shadow-card"
                        >
                            <p className="text-xs font-semibold tracking-wide text-stone-500 uppercase">
                                {label}
                            </p>
                            <p className="mt-2 text-3xl font-semibold text-stone-900">
                                {value}
                            </p>
                            <p className="mt-1 text-xs text-stone-500">
                                {hint}
                            </p>
                        </div>
                    ))}
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-xl border border-stone-200 bg-white p-5 shadow-card">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="font-semibold text-stone-900">
                                    Urgent attention
                                </h2>
                                <p className="mt-1 text-sm text-stone-500">
                                    15-day mediation and conciliation
                                    checkpoints.
                                </p>
                            </div>
                            <Link
                                href="/lupon/cases"
                                className="text-sm font-semibold text-brand"
                            >
                                All cases
                            </Link>
                        </div>
                        <div className="mt-4 divide-y divide-stone-100">
                            {deadlines.length === 0 ? (
                                <p className="py-5 text-sm text-stone-500">
                                    No active cases approaching a 15-day
                                    checkpoint.
                                </p>
                            ) : (
                                deadlines.map((deadline) => (
                                    <Link
                                        key={deadline.id}
                                        href={`/lupon/cases/${deadline.id}`}
                                        className="flex items-center justify-between gap-3 py-3"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-semibold text-stone-800">
                                                {deadline.caseNumber}
                                            </p>
                                            <p className="truncate text-sm text-stone-500">
                                                {deadline.title}
                                            </p>
                                        </div>
                                        <span
                                            className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${deadline.overdue ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'}`}
                                        >
                                            {deadline.overdue
                                                ? 'Overdue'
                                                : `Due ${deadline.dueDate}`}
                                        </span>
                                    </Link>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="rounded-xl border border-stone-200 bg-white p-5 shadow-card">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="font-semibold text-stone-900">
                                    This week&apos;s hearings
                                </h2>
                                <p className="mt-1 text-sm text-stone-500">
                                    Scheduled summons and proceedings.
                                </p>
                            </div>
                            <Link
                                href="/lupon/hearings"
                                className="text-sm font-semibold text-brand"
                            >
                                Calendar
                            </Link>
                        </div>
                        <div className="mt-4 divide-y divide-stone-100">
                            {schedule.length === 0 ? (
                                <p className="py-5 text-sm text-stone-500">
                                    No hearings scheduled for the next seven
                                    days.
                                </p>
                            ) : (
                                schedule.map((hearing) => (
                                    <Link
                                        key={hearing.id}
                                        href={`/lupon/cases/${hearing.caseId}`}
                                        className="flex items-center justify-between gap-3 py-3"
                                    >
                                        <div>
                                            <p className="font-semibold text-stone-800">
                                                {hearing.caseNumber}
                                            </p>
                                            <p className="text-sm text-stone-500">
                                                {hearing.caseTitle}
                                            </p>
                                        </div>
                                        <div className="text-right text-xs text-stone-600">
                                            <p className="font-semibold">
                                                {hearing.date}
                                                {hearing.time
                                                    ? `, ${hearing.time}`
                                                    : ''}
                                            </p>
                                            <p>
                                                {hearing.phase} ·{' '}
                                                {hearing.venue}
                                            </p>
                                        </div>
                                    </Link>
                                ))
                            )}
                        </div>
                    </section>
                </div>

                <section className="rounded-xl border border-stone-200 bg-white p-5 shadow-card">
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="font-semibold text-stone-900">
                                Recent case activity
                            </h2>
                            <p className="mt-1 text-sm text-stone-500">
                                Latest recorded case updates in your barangay.
                            </p>
                        </div>
                        <Link
                            href="/lupon/cases"
                            className="text-sm font-semibold text-brand"
                        >
                            All cases
                        </Link>
                    </div>
                    <div className="mt-4 divide-y divide-stone-100">
                        {recentActivity.length === 0 ? (
                            <p className="py-5 text-sm text-stone-500">
                                No case activity has been recorded yet.
                            </p>
                        ) : (
                            recentActivity.map((activity) => (
                                <Link
                                    key={activity.id}
                                    href={`/lupon/cases/${activity.caseId}`}
                                    className="flex items-center justify-between gap-3 py-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold text-stone-800">
                                            {activity.caseNumber ??
                                                'Lupon case'}
                                        </p>
                                        <p className="truncate text-sm text-stone-500">
                                            {activity.caseTitle}
                                        </p>
                                        <p className="mt-0.5 truncate text-xs text-stone-400">
                                            {activity.label}
                                            {activity.notes
                                                ? ` · ${activity.notes}`
                                                : ''}
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-right text-xs text-stone-500">
                                        {activity.occurredAt}
                                    </span>
                                </Link>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </LuponLayout>
    );
}
