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
                <header className="border-b border-gray-200 pb-5">
                    <p className="text-sm font-bold tracking-[0.16em] text-[#3f6f3d] uppercase">
                        Community Services
                    </p>
                    <h1 className="mt-2 text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
                        Barangay Programs
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600 sm:text-base">
                        Explore published community programs, projects, and
                        events available to you.
                    </p>
                </header>

                {programs.length === 0 ? (
                    <section className="mt-6 rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center">
                        <h2 className="text-lg font-bold text-gray-800">
                            No active programs right now
                        </h2>
                        <p className="mt-2 text-sm text-gray-500">
                            Check back for newly published barangay initiatives
                            and events.
                        </p>
                    </section>
                ) : (
                    <section className="mt-6 grid gap-4 md:grid-cols-2">
                        {programs.map((program) => (
                            <article
                                key={program.id}
                                className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <h2 className="text-lg font-bold text-gray-900">
                                        {program.title}
                                    </h2>
                                    <span className="shrink-0 rounded-full bg-[#edf4ea] px-3 py-1 text-xs font-bold text-[#315b30]">
                                        {program.eligibility}
                                    </span>
                                </div>
                                <p className="mt-3 text-sm leading-6 whitespace-pre-line text-gray-600">
                                    {program.description}
                                </p>
                                <dl className="mt-5 space-y-2 border-t border-gray-100 pt-4 text-sm">
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
            <dt className="w-24 shrink-0 font-semibold text-gray-500">
                {label}
            </dt>
            <dd className="text-gray-800">{value}</dd>
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
