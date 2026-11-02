import { caseSaveOptions } from './CaseSaveFeedback';
import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import {
    luponStatusLabel,
    type LuponStatus,
    type LuponWorkflow,
} from './types';
const dateToday = () => new Date().toLocaleDateString('en-CA');
export default function WorkflowPanel({
    caseId,
    workflow,
}: {
    caseId: number;
    workflow: LuponWorkflow;
}) {
    const form = useForm({
        status: '' as LuponStatus | '',
        expected_status: workflow.status,
        event_date: dateToday(),
        notes: '',
        pangkat_members: ['', '', ''],
        pangkat_chair_name: '',
        pangkat_secretary_name: '',
        repudiation_ground: '',
        sworn_statement_received: false,
        prerequisites_verified: false,
    });
    const extension = useForm({ days: 15, notes: '' });
    useEffect(() => {
        form.setData((current) => ({
            ...current,
            expected_status: workflow.status,
            status: '',
        }));
    }, [workflow.status]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            pangkat_members:
                data.status === 'pangkat_formed' ? data.pangkat_members : null,
        }));
        form.patch(`/lupon/cases/${caseId}/status`, {
            ...caseSaveOptions,
            onSuccess: () =>
                form.reset(
                    'status',
                    'notes',
                    'sworn_statement_received',
                    'prerequisites_verified',
                ),
        });
    };
    const needsNotes = [
        'pangkat_formed',
        'repudiated',
        'dismissed',
        'certified_to_file_action',
    ].includes(form.data.status);
    const missingHistory =
        (workflow.status === 'mediation_active' &&
            !workflow.milestones.mediation_started_at) ||
        (['pangkat_formed', 'conciliation_active'].includes(workflow.status) &&
            (!workflow.milestones.pangkat_formed_at ||
                !workflow.milestones.mediation_started_at)) ||
        (workflow.status === 'conciliation_active' &&
            !workflow.milestones.conciliation_started_at);
    return (
        <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
            <h2 className="font-bold text-slate-900">
                Itala ang pag-usad ng kaso
            </h2>
            <p className="text-xs text-stone-600">
                Choose the event that actually occurred, then review its
                required records. Available options do not confirm that
                requirements are met.
            </p>
            {!workflow.transitions.length ? (
                <p className="text-sm text-slate-500">
                    No case transition options were supplied. Recorded
                    information remains available.
                </p>
            ) : (
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-xs font-semibold text-slate-700">
                            Ano ang nangyari sa kaso?
                            <select
                                required
                                value={form.data.status}
                                onChange={(event) =>
                                    form.setData(
                                        'status',
                                        event.target.value as LuponStatus,
                                    )
                                }
                                className="mt-1 w-full rounded-lg border p-2 text-sm"
                            >
                                <option value="">
                                    Piliin muna ang aktuwal na pangyayari
                                </option>
                                {workflow.transitions
                                    .filter((status) => status !== 'settled')
                                    .map((status) => (
                                        <option key={status} value={status}>
                                            {luponStatusLabel(status)}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        {form.data.status && (
                            <label className="text-xs font-semibold text-slate-700">
                                {form.data.status === 'mediation_active'
                                    ? 'Petsa ng unang mediation meeting'
                                    : 'Petsa ng aktuwal na pangyayari'}
                                <input
                                    type="date"
                                    required
                                    max={dateToday()}
                                    value={form.data.event_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'event_date',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                        )}
                    </div>
                    {form.data.status && (
                        <fieldset className="space-y-3">
                            <p className="text-xs leading-5 text-slate-600">
                                Ilagay ang totoong petsa at maikling tala ng
                                nangyari. Ang karagdagang fields sa ibaba ay
                                para sa napili mong action.
                            </p>
                            {form.data.status === 'pangkat_formed' && (
                                <PangkatFields
                                    members={form.data.pangkat_members}
                                    chair={form.data.pangkat_chair_name}
                                    secretary={form.data.pangkat_secretary_name}
                                    onMembers={(value) =>
                                        form.setData('pangkat_members', value)
                                    }
                                    onChair={(value) =>
                                        form.setData(
                                            'pangkat_chair_name',
                                            value,
                                        )
                                    }
                                    onSecretary={(value) =>
                                        form.setData(
                                            'pangkat_secretary_name',
                                            value,
                                        )
                                    }
                                />
                            )}
                            {form.data.status === 'repudiated' && (
                                <div className="space-y-3 rounded-lg bg-amber-50 p-3">
                                    <label className="block text-xs font-semibold">
                                        Recorded ground
                                        <select
                                            required
                                            value={form.data.repudiation_ground}
                                            onChange={(event) =>
                                                form.setData(
                                                    'repudiation_ground',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-lg border bg-white p-2 text-sm"
                                        >
                                            <option value="">
                                                Select ground
                                            </option>
                                            <option value="fraud">Fraud</option>
                                            <option value="violence">
                                                Violence
                                            </option>
                                            <option value="intimidation">
                                                Intimidation
                                            </option>
                                        </select>
                                    </label>
                                    <label className="flex items-start gap-2 text-xs">
                                        <input
                                            type="checkbox"
                                            required
                                            checked={
                                                form.data
                                                    .sworn_statement_received
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'sworn_statement_received',
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        The sworn repudiation statement was
                                        received on the recorded date, within
                                        ten days of settlement.
                                    </label>
                                </div>
                            )}
                            {form.data.status ===
                                'certified_to_file_action' && (
                                <label className="flex items-start gap-2 rounded-lg bg-violet-50 p-3 text-xs text-violet-900">
                                    <input
                                        type="checkbox"
                                        required
                                        checked={
                                            form.data.prerequisites_verified
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'prerequisites_verified',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    I have verified the required confrontation,
                                    failed conciliation or recorded valid
                                    repudiation, the proper certifying
                                    officials, and the legal grounds. A deadline
                                    alone is not sufficient.
                                </label>
                            )}
                            <label className="block text-xs font-semibold text-slate-700">
                                Tala ng nangyari / dahilan / pinagbatayan{' '}
                                {needsNotes ? '(kailangan)' : '(opsyonal)'}
                                <textarea
                                    required={needsNotes}
                                    rows={3}
                                    maxLength={5000}
                                    value={form.data.notes}
                                    onChange={(event) =>
                                        form.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                            <Errors errors={form.errors} />
                            <button
                                disabled={form.processing || !form.data.status}
                                className="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : 'I-save ang case action'}
                            </button>
                            <p className="text-xs text-slate-500">
                                Use the written-settlement form to record an
                                agreement. Status and phase cannot be changed
                                independently.
                            </p>
                        </fieldset>
                    )}
                </form>
            )}
            {workflow.status === 'conciliation_active' &&
                !workflow.deadlines.some((deadline) =>
                    deadline.label.includes('approved extension'),
                ) && (
                    <details className="rounded-lg border border-slate-200 p-3">
                        <summary className="cursor-pointer text-sm font-semibold">
                            Record a Pangkat-approved extension
                        </summary>
                        <form
                            className="mt-3 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                extension.post(
                                    `/lupon/cases/${caseId}/extend-conciliation`,
                                    { ...caseSaveOptions },
                                );
                            }}
                        >
                            <label className="block text-xs">
                                Additional days (1–15)
                                <input
                                    required
                                    type="number"
                                    min={1}
                                    max={15}
                                    value={extension.data.days}
                                    onChange={(event) =>
                                        extension.setData(
                                            'days',
                                            Number(event.target.value),
                                        )
                                    }
                                    className="ml-2 rounded border p-2"
                                />
                            </label>
                            <label className="block text-xs">
                                Reason for extension
                                <textarea
                                    required
                                    value={extension.data.notes}
                                    onChange={(event) =>
                                        extension.setData(
                                            'notes',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded border p-2 text-sm"
                                />
                            </label>
                            <Errors errors={extension.errors} />
                            <button
                                disabled={extension.processing}
                                className="rounded-lg border px-3 py-2 text-xs font-semibold"
                            >
                                Save approved extension
                            </button>
                        </form>
                    </details>
                )}
            {missingHistory && (
                <HistoryForm caseId={caseId} workflow={workflow} />
            )}
        </section>
    );
}
function HistoryForm({
    caseId,
    workflow,
}: {
    caseId: number;
    workflow: LuponWorkflow;
}) {
    const form = useForm({
        mediation_started_at: workflow.milestones.mediation_started_at || '',
        pangkat_formed_at: workflow.milestones.pangkat_formed_at || '',
        conciliation_started_at:
            workflow.milestones.conciliation_started_at || '',
        pangkat_members:
            workflow.milestones.pangkat_members?.length === 3
                ? workflow.milestones.pangkat_members
                : ['', '', ''],
        pangkat_chair_name: workflow.milestones.pangkat_chair_name || '',
        pangkat_secretary_name:
            workflow.milestones.pangkat_secretary_name || '',
        notes: '',
    });
    const dateFields = [
        'mediation_started_at',
        'pangkat_formed_at',
        'conciliation_started_at',
    ] as const;
    return (
        <details className="rounded-lg border border-amber-200 bg-amber-50 p-3">
            <summary className="cursor-pointer text-sm font-semibold text-amber-900">
                Complete missing historical milestones
            </summary>
            <form
                className="mt-3 space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        pangkat_members:
                            workflow.status === 'mediation_active'
                                ? null
                                : data.pangkat_members,
                    }));
                    form.post(`/lupon/cases/${caseId}/history`, {
                        ...caseSaveOptions,
                    });
                }}
            >
                <p className="text-xs text-slate-600">
                    Use verified case records. Existing dates cannot be silently
                    replaced.
                </p>
                {dateFields.map((field) => (
                    <label key={field} className="block text-xs font-semibold">
                        {field.replaceAll('_', ' ')}
                        <input
                            type="date"
                            max={dateToday()}
                            readOnly={Boolean(workflow.milestones[field])}
                            value={form.data[field]}
                            onChange={(event) =>
                                form.setData(field, event.target.value)
                            }
                            className="mt-1 w-full rounded-lg border bg-white p-2 text-sm"
                        />
                    </label>
                ))}
                {!workflow.milestones.pangkat_members?.length &&
                    workflow.status !== 'mediation_active' && (
                        <PangkatFields
                            members={form.data.pangkat_members}
                            chair={form.data.pangkat_chair_name}
                            secretary={form.data.pangkat_secretary_name}
                            onMembers={(value) =>
                                form.setData('pangkat_members', value)
                            }
                            onChair={(value) =>
                                form.setData('pangkat_chair_name', value)
                            }
                            onSecretary={(value) =>
                                form.setData('pangkat_secretary_name', value)
                            }
                        />
                    )}
                <label className="block text-xs font-semibold">
                    Source and verification notes
                    <textarea
                        required
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        className="mt-1 w-full rounded border bg-white p-2 text-sm"
                    />
                </label>
                <Errors errors={form.errors} />
                <button
                    disabled={form.processing}
                    className="rounded-lg border bg-white px-3 py-2 text-xs font-semibold"
                >
                    Record verified history
                </button>
            </form>
        </details>
    );
}
function PangkatFields({
    members,
    chair,
    secretary,
    onMembers,
    onChair,
    onSecretary,
}: {
    members: string[];
    chair: string;
    secretary: string;
    onMembers: (members: string[]) => void;
    onChair: (value: string) => void;
    onSecretary: (value: string) => void;
}) {
    return (
        <div className="space-y-3 rounded-lg border border-blue-100 bg-blue-50 p-3">
            <p className="text-xs font-semibold text-blue-900">
                Record the three appointed Pangkat members
            </p>
            {members.map((member, index) => (
                <label key={index} className="block text-xs">
                    Member {index + 1}
                    <input
                        required
                        value={member}
                        onChange={(event) =>
                            onMembers(
                                members.map((value, key) =>
                                    key === index ? event.target.value : value,
                                ),
                            )
                        }
                        className="mt-1 w-full rounded border bg-white p-2 text-sm"
                    />
                </label>
            ))}
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-xs">
                    Pangkat chair
                    <select
                        required
                        value={chair}
                        onChange={(event) => onChair(event.target.value)}
                        className="mt-1 w-full rounded border bg-white p-2 text-sm"
                    >
                        <option value="">Choose member</option>
                        {members.filter(Boolean).map((name, index) => (
                            <option key={index}>{name}</option>
                        ))}
                    </select>
                </label>
                <label className="text-xs">
                    Pangkat secretary
                    <select
                        required
                        value={secretary}
                        onChange={(event) => onSecretary(event.target.value)}
                        className="mt-1 w-full rounded border bg-white p-2 text-sm"
                    >
                        <option value="">Choose member</option>
                        {members.filter(Boolean).map((name, index) => (
                            <option key={index}>{name}</option>
                        ))}
                    </select>
                </label>
            </div>
        </div>
    );
}
export function Errors({ errors }: { errors: Record<string, string> }) {
    if (!Object.keys(errors).length) return null;
    return (
        <ul
            role="alert"
            className="space-y-1 rounded-lg bg-red-50 p-3 text-xs text-red-800"
        >
            {Object.entries(errors).map(([key, value]) => (
                <li key={key}>{value}</li>
            ))}
        </ul>
    );
}
