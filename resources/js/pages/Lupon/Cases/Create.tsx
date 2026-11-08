import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PartyIntake from '@/components/lupon/PartyIntake';
import { EvidenceInput } from '@/components/lupon/Evidence';
import type { LuponOfficials, LuponParty } from '@/components/lupon/types';

type Props = {
    auth: { user: { name?: string } };
    caseTypes: string[];
    officials: LuponOfficials;
};
export default function CreateCase({ auth, caseTypes, officials }: Props) {
    const [camera, setCamera] = useState<'complainant' | 'respondent' | null>(
        null,
    );
    const form = useForm({
        case_type: caseTypes[0] || 'Other',
        custom_case_type: '',
        incident_date: new Date().toLocaleDateString('en-CA'),
        incident_location: '',
        narrative: '',
        requested_action: '',
        complainant_name: '',
        complainant_is_resident: true,
        complainant_resident_id: '',
        complainant_address: '',
        complainant_contact: '',
        respondent_name: '',
        respondent_is_resident: true,
        respondent_resident_id: '',
        respondent_address: '',
        respondent_contact: '',
        hearing_date: '',
        hearing_time: '',
        venue: 'Barangay Hall',
        acting_captain_id: '',
        evidence: [] as File[],
    });
    const party = (prefix: 'complainant' | 'respondent'): LuponParty => ({
        name: form.data[`${prefix}_name`],
        residentId: form.data[`${prefix}_resident_id`],
        isResident: form.data[`${prefix}_is_resident`],
        address: form.data[`${prefix}_address`],
        contact: form.data[`${prefix}_contact`],
    });
    const setParty = (
        prefix: 'complainant' | 'respondent',
        value: LuponParty,
    ) =>
        form.setData((current) => ({
            ...current,
            [`${prefix}_name`]: value.name,
            [`${prefix}_resident_id`]: value.residentId,
            [`${prefix}_is_resident`]: value.isResident,
            [`${prefix}_address`]: value.address,
            [`${prefix}_contact`]: value.contact,
        }));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        setCamera(null);
        form.post('/lupon/cases', {
            forceFormData: true,
            preserveScroll: true,
        });
    };
    return (
        <LuponLayout active="cases" user={auth.user}>
            <Head title="Walk-in Lupon Intake" />
            <main className="mx-auto max-w-6xl space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-ink-strong">
                            Walk-in case intake
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Scan or search both parties, record the dispute, and
                            prepare the first hearing notices.
                        </p>
                    </div>
                    <Link
                        href="/lupon/cases"
                        className="rounded-lg border bg-white px-4 py-2 text-sm font-semibold"
                    >
                        Back to cases
                    </Link>
                </div>
                {Object.keys(form.errors).length > 0 && (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                    >
                        <ul className="list-disc space-y-1 pl-5">
                            {Object.entries(form.errors).map(([key, error]) => (
                                <li key={key}>{error}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid gap-5 lg:grid-cols-2">
                        <PartyIntake
                            label="Complainant"
                            value={party('complainant')}
                            onChange={(value) => setParty('complainant', value)}
                            otherResidentId={form.data.respondent_resident_id}
                            autoFocus
                            cameraOpen={camera === 'complainant'}
                            onCamera={(open) =>
                                setCamera(open ? 'complainant' : null)
                            }
                        />
                        <PartyIntake
                            label="Respondent"
                            value={party('respondent')}
                            onChange={(value) => setParty('respondent', value)}
                            otherResidentId={form.data.complainant_resident_id}
                            cameraOpen={camera === 'respondent'}
                            onCamera={(open) =>
                                setCamera(open ? 'respondent' : null)
                            }
                        />
                    </div>
                    <section className="space-y-4 rounded-xl border border-line-soft bg-white p-4 sm:p-5">
                        <h2 className="font-semibold text-ink-strong">
                            Dispute details
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-semibold">
                                Nature of dispute
                                <select
                                    value={form.data.case_type}
                                    onChange={(event) =>
                                        form.setData(
                                            'case_type',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                >
                                    {caseTypes.map((type) => (
                                        <option key={type}>{type}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm font-semibold">
                                Incident date
                                <input
                                    type="date"
                                    required
                                    value={form.data.incident_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'incident_date',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                        </div>
                        {form.data.case_type === 'Other' && (
                            <label className="block text-sm font-semibold">
                                Specify nature
                                <input
                                    required
                                    maxLength={80}
                                    value={form.data.custom_case_type}
                                    onChange={(event) =>
                                        form.setData(
                                            'custom_case_type',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                        )}
                        <label className="block text-sm font-semibold">
                            Incident location
                            <input
                                required
                                maxLength={500}
                                value={form.data.incident_location}
                                onChange={(event) =>
                                    form.setData(
                                        'incident_location',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded-lg border p-2 text-sm"
                            />
                        </label>
                        <label className="block text-sm font-semibold">
                            Narrative
                            <textarea
                                required
                                rows={4}
                                maxLength={5000}
                                value={form.data.narrative}
                                onChange={(event) =>
                                    form.setData(
                                        'narrative',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded-lg border p-2 text-sm"
                            />
                        </label>
                        <label className="block text-sm font-semibold">
                            Requested action
                            <textarea
                                required
                                rows={2}
                                maxLength={2000}
                                value={form.data.requested_action}
                                onChange={(event) =>
                                    form.setData(
                                        'requested_action',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded-lg border p-2 text-sm"
                            />
                        </label>
                        <EvidenceInput
                            files={form.data.evidence}
                            onChange={(files) =>
                                form.setData('evidence', files)
                            }
                            error={form.errors.evidence}
                        />
                    </section>
                    <section className="space-y-4 rounded-xl border border-line-soft bg-white p-4 sm:p-5">
                        <h2 className="font-semibold text-ink-strong">
                            First hearing & presiding official
                        </h2>
                        <p className="text-sm text-ink-muted">
                            Enter the first hearing date, time and venue so the
                            summons can include the saved case and party
                            details. After saving the case, open Summons in
                            Overview to review and print the prepared document.
                            Preparation does not record service.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label className="text-sm font-semibold">
                                Hearing date
                                <input
                                    required
                                    type="date"
                                    value={form.data.hearing_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'hearing_date',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                            <label className="text-sm font-semibold">
                                Time
                                <input
                                    required
                                    type="time"
                                    value={form.data.hearing_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'hearing_time',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                            <label className="text-sm font-semibold">
                                Venue
                                <input
                                    required
                                    value={form.data.venue}
                                    onChange={(event) =>
                                        form.setData(
                                            'venue',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                        </div>
                        <label className="block text-sm font-semibold">
                            Acting Punong Barangay (optional)
                            <select
                                value={form.data.acting_captain_id}
                                onChange={(event) =>
                                    form.setData(
                                        'acting_captain_id',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded-lg border p-2 text-sm"
                            >
                                <option value="">
                                    {officials.punong_barangay?.name ||
                                        'Default Punong Barangay'}
                                </option>
                                {officials.active_kagawads.map((member) => (
                                    <option value={member.id} key={member.id}>
                                        {member.name} — {member.title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <p className="text-xs text-ink-muted">
                            Notices are prepared from this schedule. Verify any
                            filing fee or waiver and record actual service
                            separately. The mediation clock starts at the
                            parties’ first meeting.
                        </p>
                    </section>
                    <div className="flex justify-end">
                        <button
                            disabled={form.processing}
                            className="rounded-lg bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            {form.processing
                                ? 'Docketing…'
                                : 'Docket case & prepare notices'}
                        </button>
                    </div>
                </form>
            </main>
        </LuponLayout>
    );
}
