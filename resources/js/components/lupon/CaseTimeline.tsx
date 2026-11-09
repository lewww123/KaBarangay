import type { LuponWorkflow } from './types';
import { luponStatusClasses, luponStatusLabel } from './types';
export function LuponStatusBadge({ status }: { status: string }) {
    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${luponStatusClasses(status)}`}
        >
            {luponStatusLabel(status)}
        </span>
    );
}
export default function CaseTimeline({
    workflow,
}: {
    workflow?: LuponWorkflow;
}) {
    if (!workflow) return null;
    return (
        <section
            className="space-y-4 rounded-xl border border-line-soft bg-white p-4 sm:p-5"
            aria-label="Case timeline"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold text-ink-strong">
                    Recorded history & deadlines
                </h2>
                <LuponStatusBadge status={workflow.trackingStatus} />
            </div>
            <p className="text-xs text-ink-muted">
                Meeting-based deadlines are tracked separately. Expiry does not
                automatically issue a CFA.
            </p>
            {workflow.deadlines.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2">
                    {workflow.deadlines.map((deadline) => (
                        <div
                            key={deadline.label}
                            className={`rounded-lg border p-3 ${deadline.active !== false && deadline.daysRemaining < 0 ? 'border-red-200 bg-red-50' : 'border-line-soft bg-canvas'}`}
                        >
                            <p className="text-xs font-semibold text-ink">
                                {deadline.label}
                            </p>
                            <p className="mt-1 text-sm font-semibold">
                                {new Date(deadline.date).toLocaleDateString(
                                    'en-PH',
                                )}
                            </p>
                            <p className="text-xs text-ink-muted">
                                {deadline.active === false
                                    ? 'Recorded deadline'
                                    : deadline.daysRemaining < 0
                                      ? `${-deadline.daysRemaining} days overdue`
                                      : deadline.daysRemaining === 0
                                        ? 'Due today'
                                        : `${deadline.daysRemaining} days remaining`}
                            </p>
                        </div>
                    ))}
                </div>
            )}
            <ol className="space-y-4 border-l border-brand-muted pl-4">
                {[...workflow.events]
                    .sort(
                        (a, b) =>
                            new Date(a.date).getTime() -
                                new Date(b.date).getTime() || a.id - b.id,
                    )
                    .map((event) => (
                        <li key={event.id} className="relative">
                            <span
                                aria-hidden="true"
                                className="absolute top-1.5 -left-[21px] h-2 w-2 rounded-full bg-brand"
                            />
                            <p className="text-sm font-semibold text-ink-strong">
                                {luponStatusLabel(event.to)}
                            </p>
                            <time
                                dateTime={event.date}
                                className="text-xs text-ink-muted"
                            >
                                {event.date.includes('T')
                                    ? new Date(event.date).toLocaleString(
                                          'en-PH',
                                      )
                                    : new Date(event.date).toLocaleDateString(
                                          'en-PH',
                                      )}
                            </time>
                            <p className="text-xs text-stone-500">
                                Responsible staff: not supplied in this event
                                record
                            </p>
                            {typeof event.metadata?.document_id ===
                                'number' && (
                                <p className="text-xs text-stone-500">
                                    Document ID: {event.metadata.document_id}
                                </p>
                            )}
                            {event.notes && (
                                <p className="mt-1 text-xs whitespace-pre-wrap text-ink-muted">
                                    {event.notes}
                                </p>
                            )}
                        </li>
                    ))}
            </ol>
            {!workflow.events.length && (
                <p className="text-xs text-ink-muted">
                    No case events supplied. History is not inferred from the
                    current status.
                </p>
            )}
        </section>
    );
}
