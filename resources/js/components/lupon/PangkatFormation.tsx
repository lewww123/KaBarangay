import { caseSaveOptions } from './CaseSaveFeedback';
import { useForm } from '@inertiajs/react';
import { Errors } from './WorkflowPanel';
import type { LuponWorkflow } from './types';
const today = () =>
    new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const plusDays = (value: string, days: number) =>
    new Date(Date.parse(value + 'T00:00:00Z') + days * 86400000)
        .toISOString()
        .slice(0, 10);
export default function PangkatFormation({
    caseId,
    workflow,
    candidates,
}: {
    caseId: number;
    workflow: LuponWorkflow;
    candidates: string[];
}) {
    const form = useForm({
        status: 'pangkat_formed',
        expected_status: workflow.status,
        event_date: today(),
        pangkat_members: ['', '', ''],
        pangkat_chair_name: '',
        pangkat_secretary_name: '',
        notes: '',
        first_hearing_date: '',
        first_hearing_time: '',
        first_hearing_venue: 'Barangay Hall',
    });
    const members = form.data.pangkat_members;
    const updateMember = (index: number, value: string) => {
        const next = members.map((member, position) =>
            position === index ? value : member,
        );
        form.setData((data) => ({
            ...data,
            pangkat_members: next,
            pangkat_chair_name: next.includes(data.pangkat_chair_name)
                ? data.pangkat_chair_name
                : '',
            pangkat_secretary_name: next.includes(data.pangkat_secretary_name)
                ? data.pangkat_secretary_name
                : '',
        }));
    };
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(`/lupon/cases/${caseId}/status`, {
                    ...caseSaveOptions,
                });
            }}
        >
            <h2 className="text-lg font-semibold">
                Proceed to Lupon — Pangkat conciliation
            </h2>
            <p className="text-sm text-stone-600">
                Kumpletuhin ang 3 Pangkat members at unang hearing. Pag-save,
                mamamarkahang Done ang Kapitan phase at magiging current ang
                Lupon phase.
            </p>
            <label className="block text-sm font-semibold">
                Petsa ng pagbuo ng Pangkat
                <input
                    required
                    type="date"
                    min={workflow.milestones.mediation_started_at ?? undefined}
                    max={today()}
                    value={form.data.event_date}
                    onChange={(e) => form.setData('event_date', e.target.value)}
                    className="mt-1 w-full rounded-lg border p-2"
                />
            </label>
            <fieldset className="space-y-3 rounded-lg border p-3">
                <legend className="px-1 font-semibold">
                    Pumili ng 3 magkakaibang Lupon members
                </legend>
                {members.map((member, index) => (
                    <label key={index} className="block text-sm">
                        Member {index + 1}
                        <input
                            required
                            list={`lupon-member-${index}`}
                            value={member}
                            onChange={(e) =>
                                updateMember(index, e.target.value)
                            }
                            placeholder="Pumili o ilagay ang pangalan ng appointed member"
                            className="mt-1 w-full rounded-lg border p-2"
                        />
                        <datalist id={`lupon-member-${index}`}>
                            {candidates
                                .filter(
                                    (name) =>
                                        name === member ||
                                        !members.includes(name),
                                )
                                .map((name) => (
                                    <option key={name} value={name} />
                                ))}
                        </datalist>
                    </label>
                ))}
                <p className="text-xs text-stone-500">
                    Mula sa barangay Lupon roster ang mga mungkahi. Kung wala pa
                    sa roster, ilagay ang buong pangalan ng appointed member.
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    {(
                        [
                            'pangkat_chair_name',
                            'pangkat_secretary_name',
                        ] as const
                    ).map((field) => (
                        <label key={field} className="text-sm">
                            {field === 'pangkat_chair_name'
                                ? 'Pangkat chair'
                                : 'Pangkat secretary'}
                            <select
                                aria-label={
                                    field === 'pangkat_chair_name'
                                        ? 'Pangkat chair'
                                        : 'Pangkat secretary'
                                }
                                required
                                value={form.data[field]}
                                onChange={(e) => {
                                    const value = e.target.value;
                                    form.setData((data) => ({
                                        ...data,
                                        [field]: value,
                                    }));
                                }}
                                className="mt-1 w-full rounded-lg border p-2"
                            >
                                <option value="">
                                    Pumili mula sa 3 members
                                </option>
                                {members.filter(Boolean).map((name, index) => (
                                    <option
                                        key={index}
                                        value={name}
                                        disabled={
                                            field === 'pangkat_chair_name'
                                                ? name ===
                                                  form.data
                                                      .pangkat_secretary_name
                                                : name ===
                                                  form.data.pangkat_chair_name
                                        }
                                    >
                                        {name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ))}
                </div>
            </fieldset>
            <label className="block text-sm">
                Unsuccessful mediation / constitution notes
                <textarea
                    required
                    maxLength={5000}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    className="mt-1 w-full rounded-lg border p-2"
                />
            </label>
            <fieldset className="space-y-3 rounded-lg border p-3">
                <legend className="px-1 font-semibold">Lupon hearing #1</legend>
                <p className="text-sm text-stone-600">
                    Unang hearing: within 3 days mula sa pagbuo ng Pangkat.
                    Hanggang 3 hearing sa Lupon phase, sa loob ng initial 15-day
                    conciliation period mula sa unang meeting. Ang approved
                    extension ay hiwalay na itinatala.
                </p>
                <label className="block text-sm">
                    Hearing date
                    <input
                        required
                        type="date"
                        min={today()}
                        max={
                            form.data.event_date
                                ? plusDays(form.data.event_date, 3)
                                : undefined
                        }
                        value={form.data.first_hearing_date}
                        onChange={(e) =>
                            form.setData('first_hearing_date', e.target.value)
                        }
                        className="mt-1 w-full rounded-lg border p-2"
                    />
                </label>
                <label className="block text-sm">
                    Time
                    <input
                        required
                        type="time"
                        value={form.data.first_hearing_time}
                        onChange={(e) =>
                            form.setData('first_hearing_time', e.target.value)
                        }
                        className="mt-1 w-full rounded-lg border p-2"
                    />
                </label>
                <label className="block text-sm">
                    Venue
                    <input
                        required
                        maxLength={255}
                        value={form.data.first_hearing_venue}
                        onChange={(e) =>
                            form.setData('first_hearing_venue', e.target.value)
                        }
                        className="mt-1 w-full rounded-lg border p-2"
                    />
                </label>
            </fieldset>
            <Errors errors={form.errors} />
            <button
                disabled={
                    form.processing ||
                    new Set(members.map((name) => name.trim().toLowerCase()))
                        .size !== 3
                }
                className="rounded-lg bg-brand px-4 py-3 text-sm font-semibold text-white disabled:opacity-40"
            >
                {form.processing
                    ? 'Saving Pangkat and scheduling hearing…'
                    : 'Save Pangkat and proceed to Lupon hearing #1'}
            </button>
        </form>
    );
}
