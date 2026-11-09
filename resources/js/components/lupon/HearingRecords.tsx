import { caseSaveOptions } from './CaseSaveFeedback';
import type { LuponDocument } from './types';
import { useForm } from '@inertiajs/react';
import { useContext, useEffect, useRef, useState } from 'react';
import { HearingPanels } from './HearingPanels';
import { hearingSummary } from './caseGuide';
import { Errors } from './WorkflowPanel';
export type HearingRecord = {
    id: number;
    hearingNumber: number;
    phase: string;
    date: string;
    time?: string | null;
    venue: string;
    status: string;
    outcome?: string | null;
    notes?: string | null;
    officerName?: string | null;
};
export default function HearingRecords({
    hearings,
    hasSettlement,
    documents: _documents = [],
    currentPhase,
    onSettle,
    onNext,
    maxDate,
    actionsUnlocked = true,
    pangkat,
}: {
    hearings: HearingRecord[];
    hasSettlement: boolean;
    documents?: LuponDocument[];
    currentPhase?: string;
    onSettle?: () => void;
    onNext?: (hearing?: HearingRecord, notes?: string) => void;
    maxDate?: string;
    actionsUnlocked?: boolean;
    pangkat?: {
        pangkat_members?: string[];
        pangkat_chair_name?: string | null;
        pangkat_secretary_name?: string | null;
    };
}) {
    const panels = useContext(HearingPanels);
    const [isOpen, setIsOpen] = useState(false);
    const [showPhaseActions, setShowPhaseActions] = useState(false);
    useEffect(() => {
        setShowPhaseActions(false);
    }, [currentPhase]);
    const [selected, setSelected] = useState<HearingRecord | null>(null);
    const form = useForm({
        status: 'Held',
        outcome: 'Ongoing',
        notes: '',
        hearing_date: '',
        hearing_time: '',
        venue: 'Barangay Hall',
    });

    const drafts = useRef<Record<number, typeof form.data>>({});
    const dialog = useRef<HTMLDialogElement>(null);
    const [clock, setClock] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setClock(Date.now()), 30000);
        return () => clearInterval(timer);
    }, []);
    const displayStatus = (hearing: HearingRecord) =>
        ['Scheduled', 'Rescheduled'].includes(hearing.status) &&
        new Date(
            hearing.date + ' ' + (hearing.time || '00:00') + ' GMT+0800',
        ).getTime() <= clock
            ? 'In progress'
            : hearing.status;
    useEffect(() => {
        setSelected((current) =>
            current
                ? (hearings.find((h) => h.id === current.id) ?? null)
                : null,
        );
    }, [hearings]);
    const summary = hearingSummary(hearings, currentPhase);
    const saveDraft = () => {
        if (selected) drafts.current[selected.id] = { ...form.data };
    };
    const openRecord = (hearing: HearingRecord) => {
        saveDraft();
        form.setData(
            drafts.current[hearing.id] ?? {
                status: ['Scheduled', 'Rescheduled'].includes(hearing.status)
                    ? 'Held'
                    : hearing.status,
                outcome: hearing.outcome || 'Ongoing',
                notes: hearing.notes || '',
                hearing_date: '',
                hearing_time: '',
                venue: hearing.venue,
            },
        );
        form.clearErrors();
        setSelected(hearing);
        setIsOpen(true);
    };
    useEffect(() => {
        if (!isOpen) return;
        const current = hearingSummary(
            hearings.filter((hearing) => hearing.phase === currentPhase),
            currentPhase,
        );
        if (current) openRecord(current);
    }, [currentPhase]);
    const closeRecords = () => {
        saveDraft();
        setSelected(null);
        setIsOpen(false);
    };
    useEffect(() => {
        if (isOpen && !dialog.current?.open) dialog.current?.showModal();
        if (!isOpen && dialog.current?.open) dialog.current.close();
    }, [isOpen]);
    return (
        <>
            {summary ? (
                <section className="rounded-xl border border-stone-200 bg-stone-50 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold text-stone-500">
                                {['Scheduled', 'Rescheduled'].includes(
                                    summary.status,
                                )
                                    ? 'Next scheduled hearing'
                                    : 'Latest recorded hearing'}
                            </p>
                            <h3 className="mt-1 font-semibold">
                                Hearing #{summary.hearingNumber} ·{' '}
                                {summary.phase}
                            </h3>
                            <p className="mt-1 text-sm text-stone-600">
                                {summary.date} {summary.time} · {summary.venue}
                            </p>
                            <p className="mt-1 text-sm">
                                {displayStatus(summary)}
                                {summary.outcome ? ' · ' + summary.outcome : ''}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => openRecord(summary)}
                            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white"
                        >
                            Open {currentPhase} hearings (
                            {
                                hearings.filter((h) => h.phase === currentPhase)
                                    .length
                            }
                            /3)
                        </button>
                    </div>
                    <p className="mt-3 text-xs text-stone-500">
                        Browse saved hearings by phase. Only recorded hearings
                        appear.
                    </p>
                </section>
            ) : (
                <p className="py-5 text-center text-sm text-ink-muted">
                    No hearings scheduled yet.
                    <button
                        type="button"
                        onClick={() => setIsOpen(true)}
                        className="ml-3 rounded-lg bg-brand px-4 py-2 text-white"
                    >
                        Open case hearing records
                    </button>
                </p>
            )}
            <dialog
                ref={dialog}
                aria-label="Case hearing records"
                onCancel={(event) => {
                    event.preventDefault();
                    if (showPhaseActions) setShowPhaseActions(false);
                    else if (
                        !form.processing &&
                        !document.getElementById('hearing-modal-host')
                            ?.childElementCount
                    )
                        closeRecords();
                }}
                className="m-auto max-h-[92vh] w-[calc(100%-1.5rem)] max-w-6xl overflow-auto rounded-xl border-0 bg-white p-0 text-stone-900 shadow-xl backdrop:bg-black/50"
            >
                {isOpen && (
                    <>
                        <header className="flex items-center justify-between gap-3 border-b p-4">
                            <div>
                                <h2 className="font-semibold">
                                    Case hearing records
                                </h2>
                                <p className="text-xs text-stone-500">
                                    Choose a hearing card to inspect its saved
                                    details.
                                </p>
                            </div>
                            <button
                                type="button"
                                disabled={form.processing}
                                onClick={closeRecords}
                                className="rounded-lg border px-3 py-2 text-sm"
                            >
                                Close records
                            </button>
                        </header>
                        <div className="grid gap-4 p-4 md:grid-cols-[15rem_minmax(0,1fr)]">
                            <nav
                                aria-label="Saved hearings"
                                className="max-h-64 space-y-4 overflow-y-auto md:max-h-[70vh]"
                            >
                                {[
                                    ...new Set(
                                        hearings.map(
                                            (hearing) => hearing.phase,
                                        ),
                                    ),
                                ]
                                    .sort(
                                        (a, b) =>
                                            Number(b === currentPhase) -
                                            Number(a === currentPhase),
                                    )
                                    .map((phase) => (
                                        <section key={phase}>
                                            <h3 className="mb-2 text-xs font-semibold text-stone-500 uppercase">
                                                {phase} ·{' '}
                                                {phase === currentPhase
                                                    ? 'Current phase'
                                                    : 'Done — view records'}
                                            </h3>
                                            <div className="space-y-2">
                                                {hearings
                                                    .filter(
                                                        (hearing) =>
                                                            hearing.phase ===
                                                            phase,
                                                    )
                                                    .sort(
                                                        (a, b) =>
                                                            a.hearingNumber -
                                                                b.hearingNumber ||
                                                            a.id - b.id,
                                                    )
                                                    .map((hearing) => (
                                                        <button
                                                            key={hearing.id}
                                                            type="button"
                                                            aria-pressed={
                                                                selected?.id ===
                                                                hearing.id
                                                            }
                                                            disabled={
                                                                form.processing
                                                            }
                                                            onClick={() =>
                                                                openRecord(
                                                                    hearing,
                                                                )
                                                            }
                                                            className={
                                                                'block w-full rounded-xl border p-3 text-left ' +
                                                                (selected?.id ===
                                                                hearing.id
                                                                    ? 'border-brand bg-brand-soft'
                                                                    : 'border-stone-200 hover:bg-stone-50')
                                                            }
                                                        >
                                                            <span className="block text-sm font-semibold">
                                                                Hearing #
                                                                {
                                                                    hearing.hearingNumber
                                                                }
                                                            </span>
                                                            <span className="block text-xs">
                                                                {hearing.date}{' '}
                                                                {hearing.time}
                                                            </span>
                                                            <span className="mt-1 block text-xs text-stone-600">
                                                                {displayStatus(
                                                                    hearing,
                                                                )}
                                                                {hearing.outcome
                                                                    ? ' · ' +
                                                                      hearing.outcome
                                                                    : ''}
                                                            </span>
                                                        </button>
                                                    ))}
                                            </div>
                                        </section>
                                    ))}
                            </nav>
                            <section
                                aria-label="Selected hearing details"
                                className="min-w-0 space-y-4 md:max-h-[70vh] md:overflow-y-auto"
                            >
                                {selected?.phase === 'Lupon' &&
                                    pangkat?.pangkat_members?.length === 3 && (
                                        <section className="space-y-2 rounded-lg border border-brand-muted bg-brand-soft p-4">
                                            <h3 className="font-semibold">
                                                Pangkat assigned to conciliation
                                            </h3>
                                            <ol className="list-inside list-decimal text-sm">
                                                {pangkat.pangkat_members.map(
                                                    (name) => (
                                                        <li key={name}>
                                                            {name}
                                                            {name ===
                                                            pangkat.pangkat_chair_name
                                                                ? ' · Chair'
                                                                : name ===
                                                                    pangkat.pangkat_secretary_name
                                                                  ? ' · Secretary'
                                                                  : ' · Member'}
                                                        </li>
                                                    ),
                                                )}
                                            </ol>
                                            <p className="text-xs">
                                                Maximum 3 hearings · initial
                                                15-day conciliation period
                                                {maxDate
                                                    ? ' · Current deadline: ' +
                                                      maxDate
                                                    : ''}
                                            </p>
                                        </section>
                                    )}
                                {hasSettlement && (
                                    <button
                                        type="button"
                                        onClick={() => onSettle?.()}
                                        className="rounded-lg bg-brand px-4 py-2 text-sm text-white"
                                    >
                                        View amicable settlement
                                    </button>
                                )}
                                {selected && (
                                    <>
                                        <div className="space-y-2 rounded-xl border border-stone-200 p-4">
                                            <h3 className="font-semibold">
                                                Hearing #
                                                {selected.hearingNumber} ·{' '}
                                                {selected.phase}
                                            </h3>
                                            <p className="text-sm">
                                                {selected.date} {selected.time}{' '}
                                                · {selected.venue}
                                            </p>
                                            <p className="text-sm">
                                                {displayStatus(selected)}
                                                {selected.outcome
                                                    ? ' · ' + selected.outcome
                                                    : ''}
                                            </p>
                                            <p className="text-sm">
                                                Officer:{' '}
                                                {selected.officerName ||
                                                    'Not supplied'}
                                            </p>
                                            <p className="text-xs text-stone-500">
                                                Attendance details are
                                                unavailable.
                                            </p>
                                            <p className="text-sm whitespace-pre-wrap">
                                                {selected.notes ||
                                                    'No notes recorded.'}
                                            </p>
                                        </div>
                                        {selected.phase === currentPhase &&
                                            selected.outcome !== 'Settled' && (
                                                <div className="flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            form.processing ||
                                                            (!actionsUnlocked &&
                                                                displayStatus(
                                                                    selected,
                                                                ) !==
                                                                    'In progress' &&
                                                                selected.status !==
                                                                    'Held')
                                                        }
                                                        onClick={() =>
                                                            onSettle?.()
                                                        }
                                                        className="rounded-lg bg-brand p-3 text-sm text-white disabled:opacity-40"
                                                    >
                                                        Settled — record
                                                        agreement
                                                    </button>
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            form.processing ||
                                                            (!actionsUnlocked &&
                                                                displayStatus(
                                                                    selected,
                                                                ) !==
                                                                    'In progress' &&
                                                                selected.status !==
                                                                    'Held')
                                                        }
                                                        onClick={() => {
                                                            if (
                                                                selected.hearingNumber <
                                                                3
                                                            ) {
                                                                onNext?.(
                                                                    selected,
                                                                    form.data
                                                                        .notes,
                                                                );
                                                                return;
                                                            }
                                                            form.transform(
                                                                (data) => ({
                                                                    status: 'Held',
                                                                    outcome:
                                                                        'Unresolved',
                                                                    notes: data.notes,
                                                                }),
                                                            );
                                                            form.patch(
                                                                '/lupon/hearings/' +
                                                                    selected.id,
                                                                {
                                                                    ...caseSaveOptions,
                                                                    onSuccess:
                                                                        () => {
                                                                            if (
                                                                                selected.hearingNumber >=
                                                                                3
                                                                            )
                                                                                setShowPhaseActions(
                                                                                    true,
                                                                                );
                                                                            else {
                                                                                onNext?.();
                                                                            }
                                                                        },
                                                                },
                                                            );
                                                        }}
                                                        className="rounded-lg border p-3 text-sm disabled:opacity-40"
                                                    >
                                                        {form.processing
                                                            ? 'Saving hearing outcome…'
                                                            : selected.hearingNumber >=
                                                                3
                                                              ? currentPhase ===
                                                                'Kapitan'
                                                                  ? 'Unresolved — proceed to Lupon phase'
                                                                  : 'Unresolved — proceed to final outcome'
                                                              : 'Unresolved — next hearing'}
                                                    </button>
                                                </div>
                                            )}
                                        <Errors errors={form.errors} />
                                        {selected.phase === currentPhase && (
                                            <details key={selected.id}>
                                                <summary className="cursor-pointer rounded-lg border p-3 text-sm font-semibold">
                                                    Hearing notes
                                                </summary>
                                                <div className="mt-3">
                                                    {' '}
                                                    <form
                                                        className="space-y-4 rounded-xl border border-stone-200 bg-white p-4"
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
                                                            form.transform(
                                                                (data) => ({
                                                                    notes: data.notes,
                                                                }),
                                                            );
                                                            form.patch(
                                                                `/lupon/hearings/${selected.id}`,
                                                                {
                                                                    ...caseSaveOptions,
                                                                    onSuccess:
                                                                        () => {
                                                                            delete drafts
                                                                                .current[
                                                                                selected
                                                                                    .id
                                                                            ];
                                                                        },
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        <h3 className="font-semibold">
                                                            Hearing #
                                                            {
                                                                selected.hearingNumber
                                                            }
                                                        </h3>
                                                        <label className="block text-xs font-semibold">
                                                            Hearing record /
                                                            notes
                                                            <textarea
                                                                rows={4}
                                                                value={
                                                                    form.data
                                                                        .notes
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    form.setData(
                                                                        'notes',
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                                className="mt-1 w-full rounded border p-2 text-sm"
                                                            />
                                                        </label>
                                                        <Errors
                                                            errors={form.errors}
                                                        />
                                                        <div className="flex justify-end gap-2">
                                                            <button
                                                                type="button"
                                                                onClick={
                                                                    closeRecords
                                                                }
                                                                className="rounded border px-3 py-2 text-xs"
                                                            >
                                                                Cancel
                                                            </button>
                                                            <button
                                                                disabled={
                                                                    form.processing
                                                                }
                                                                className="rounded bg-brand px-3 py-2 text-xs font-semibold text-white"
                                                            >
                                                                {form.processing
                                                                    ? 'Saving hearing notes…'
                                                                    : 'Save hearing notes'}
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </details>
                                        )}
                                    </>
                                )}
                                {!hearings.some(
                                    (h) => h.phase === currentPhase,
                                ) &&
                                    currentPhase !== 'Closed' && (
                                        <button
                                            type="button"
                                            onClick={() => onNext?.()}
                                            className="rounded-lg bg-brand p-3 text-sm text-white"
                                        >
                                            Schedule hearing #1 · {currentPhase}
                                        </button>
                                    )}
                            </section>
                        </div>
                    </>
                )}
                {showPhaseActions && (
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-label="Next phase"
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    >
                        <div className="max-h-[85vh] w-full max-w-2xl overflow-auto rounded-xl bg-white p-5">
                            <button
                                type="button"
                                onClick={() => setShowPhaseActions(false)}
                                className="mb-3 rounded border px-3 py-2"
                            >
                                Close phase action
                            </button>
                            {panels.resolution}
                        </div>
                    </div>
                )}
                <div id="hearing-modal-host" />
            </dialog>
        </>
    );
}
