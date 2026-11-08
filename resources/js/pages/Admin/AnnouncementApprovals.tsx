import withDeferredPage from '@/components/withDeferredPage';
import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import AdminLayout from '@/layouts/AdminLayout';
import {
    formatAnnouncementStatusLabel,
    type AnnouncementTab,
} from '@/components/announcements/announcement';

interface Program {
    id: number;
    title: string;
    content: string;
    category: string;
    requires_attendance: boolean;
    program_status: 'draft' | 'ongoing' | 'done';
    event_start_at?: string | null;
    location?: string | null;
    author?: { name: string } | null;
}

export default withDeferredPage(
    AnnouncementApprovals,
    ['announcements'],
    () => (
        <AdminLayout active={'overview'}>
            <AdminPageSkeleton rows={5} />
        </AdminLayout>
    ),
);

function AnnouncementApprovals({
    announcements,
}: {
    announcements: Program[];
}) {
    const [tab, setTab] = useState<AnnouncementTab>('all');
    const [search, setSearch] = useState('');
    const programs = announcements.filter(
        (program) =>
            program.category === 'Event' || program.requires_attendance,
    );
    const visible = programs.filter(
        (program) =>
            (tab === 'all' || program.program_status === tab) &&
            `${program.title} ${program.content}`
                .toLowerCase()
                .includes(search.toLowerCase()),
    );

    return (
        <AdminLayout active="overview">
            <Head title="Programs" />

                <div className="space-y-5">
                    <div>
                        <h1 className="text-xl font-semibold text-ink-strong">
                            Programs
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Programs are published directly by Clerk, PWD, and
                            Senior staff.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(['all', 'draft', 'ongoing', 'done'] as const).map(
                            (status) => (
                                <button
                                    key={status}
                                    type="button"
                                    onClick={() => setTab(status)}
                                    className={`rounded-full px-4 py-2 text-sm capitalize ${tab === status ? 'bg-brand text-white' : 'bg-secondary text-ink'}`}
                                >
                                    {status} (
                                    {
                                        programs.filter(
                                            (item) =>
                                                status === 'all' ||
                                                item.program_status === status,
                                        ).length
                                    }
                                    )
                                </button>
                            ),
                        )}
                    </div>
                    <input
                        aria-label="Search programs"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search programs..."
                        className="w-full rounded-lg border-line text-sm"
                    />
                    {visible.length === 0 && (
                        <p className="rounded-lg bg-white p-6 text-sm text-ink-muted">
                            No programs found.
                        </p>
                    )}
                    {visible.map((program) => (
                        <article
                            key={program.id}
                            className="rounded-lg border border-line-soft bg-white p-5 shadow-card"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="font-semibold text-ink-strong">
                                    {program.title}
                                </h2>
                                <span className="rounded bg-brand-soft px-2 py-1 text-xs font-semibold text-brand">
                                    {formatAnnouncementStatusLabel(program)}
                                </span>
                            </div>
                            <p className="mt-3 text-sm whitespace-pre-line text-ink">
                                {program.content}
                            </p>
                            <p className="mt-3 text-xs text-ink-muted">
                                {program.author?.name ?? 'Staff'}
                                {program.location
                                    ? ` · ${program.location}`
                                    : ''}
                                {program.event_start_at
                                    ? ` · ${new Date(program.event_start_at).toLocaleString()}`
                                    : ''}
                            </p>
                        </article>
                    ))}
                </div>

        </AdminLayout>
    );
}
