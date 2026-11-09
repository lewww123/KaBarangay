import { Deferred, Head } from '@inertiajs/react';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import ResidentLayout from '@/layouts/ResidentLayout';

type Program = {
    id: number;
    title: string;
    description: string;
    category: string;
    schedule: string | null;
    location: string | null;
    eligibility: string;
    requires_attendance: boolean;
};

type Props = {
    programs?: Program[];
};

export default function Programs(props: Props) {
    return (
        <Deferred
            data={['programs']}
            fallback={
                <ResidentLayout active="programs">
                    <PortalPageSkeleton list />
                </ResidentLayout>
            }
        >
            <ProgramsContent programs={props.programs ?? []} />
        </Deferred>
    );
}

function ProgramsContent({ programs }: { programs: Program[] }) {
    return (
        <ResidentLayout active="programs">
            <Head title="Barangay Programs" />

            <div className="mx-auto max-w-5xl">
                <header>
                    <p className="text-xs font-semibold tracking-[0.16em] text-brand uppercase">
                        Community Services
                    </p>
                    <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                        Barangay Programs
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-ink-muted">
                        Explore published community programs, projects, and
                        events available to you.
                    </p>
                </header>

                {programs.length === 0 ? (
                    <section className="mt-6 rounded-xl border border-dashed border-line bg-white p-10 text-center">
                        <h2 className="text-base font-semibold tracking-tight text-ink-strong">
                            No active programs right now
                        </h2>
                        <p className="mt-2 text-sm text-ink-muted">
                            Check back for newly published barangay initiatives
                            and events.
                        </p>
                    </section>
                ) : (
                    <section className="mt-6 grid gap-4 md:grid-cols-2">
                        {programs.map((program) => (
                            <article
                                key={program.id}
                                className="rounded-xl border border-line-soft bg-white p-5 shadow-card"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <h2 className="text-base font-semibold tracking-tight text-ink-strong">
                                        {program.title}
                                    </h2>
                                    <span className="shrink-0 rounded-full bg-canvas px-3 py-1 text-xs font-semibold text-brand-deep">
                                        {program.eligibility}
                                    </span>
                                </div>
                                <p className="mt-3 text-sm leading-6 whitespace-pre-line text-ink-muted">
                                    {program.description}
                                </p>
                                <dl className="mt-5 space-y-2 border-t border-line-soft pt-4 text-sm">
                                    <ProgramDetail
                                        label="Schedule"
                                        value={
                                            program.schedule
                                                ? formatSchedule(
                                                      program.schedule,
                                                  )
                                                : 'Schedule to be announced'
                                        }
                                    />
                                    <ProgramDetail
                                        label="Location"
                                        value={
                                            program.location ||
                                            'Location to be announced'
                                        }
                                    />
                                    <ProgramDetail
                                        label="Participation"
                                        value={
                                            program.requires_attendance
                                                ? 'Attendance is required'
                                                : 'Open participation'
                                        }
                                    />
                                </dl>
                            </article>
                        ))}
                    </section>
                )}
            </div>
        </ResidentLayout>
    );
}

function ProgramDetail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-3">
            <dt className="w-24 shrink-0 font-semibold text-ink-muted">
                {label}
            </dt>
            <dd className="text-ink-strong">{value}</dd>
        </div>
    );
}

function formatSchedule(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(date);
}
