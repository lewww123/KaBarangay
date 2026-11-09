import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PartyIntake from '@/components/lupon/PartyIntake';
import { EvidenceInput } from '@/components/lupon/Evidence';
import type { LuponParty } from '@/components/lupon/types';

type Props = {
    auth: { user: { name?: string } };
    complaintTypes: string[];
    userName?: string;
};

export default function WalkInComplaint({
    auth,
    complaintTypes,
    userName,
}: Props) {
    const [camera, setCamera] = useState<'complainant' | 'respondent' | null>(
        null,
    );
    const form = useForm({
        submission_token: globalThis.crypto.randomUUID(),
        complaint_type: complaintTypes[0] || 'Other',
        incident_date: new Date().toLocaleDateString('en-CA'),
        incident_time: '',
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
        form.post('/lupon/complaints/walk-in', {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <LuponLayout active="complaints" user={auth.user}>
            <Head title="Walk-in Complaint Intake" />
            <main className="mx-auto max-w-6xl space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold text-ink-strong">
                            Walk-in complaint intake
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Record the dispute exactly as presented at the desk
                            {userName ? ` — signed in as ${userName}` : ''}.
                        </p>
                    </div>
                    <Link
                        href="/lupon/complaints"
                        className="rounded-lg border bg-white px-4 py-2 text-sm font-semibold"
                    >
                        Back to submissions
                    </Link>
                </div>

                <section className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <h2 className="text-sm font-semibold text-amber-900">
                        This submits a complaint — it does not create an
                        official case
                    </h2>
                    <p className="mt-1 text-sm text-amber-900">
                        Saving records a complaint submission in the Complaint
                        Submissions queue only. No case number, docket, hearing,
                        summons, or case document is created. A Lupon secretary
                        reviews it and assesses the filing fee, and an official
                        case is docketed only after the actual payment and
                        official receipt are verified.
                    </p>
                </section>

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
                        <p className="text-xs text-ink-muted">
                            An unregistered complainant is kept from the details
                            entered above. Linking either party to the masterlist
                            replaces the typed details with the verified
                            resident record.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="text-sm font-semibold">
                                Nature of dispute
                                <select
                                    value={form.data.complaint_type}
                                    onChange={(event) =>
                                        form.setData(
                                            'complaint_type',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                >
                                    {complaintTypes.map((type) => (
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
                            <label className="text-sm font-semibold">
                                Incident time (optional)
                                <input
                                    type="time"
                                    value={form.data.incident_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'incident_time',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border p-2 text-sm"
                                />
                            </label>
                            <label className="text-sm font-semibold">
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
                        </div>
                        <label className="block text-sm font-semibold">
                            Narrative
                            <textarea
                                required
                                rows={4}
                                maxLength={5000}
                                value={form.data.narrative}
                                onChange={(event) =>
                                    form.setData('narrative', event.target.value)
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
                            onChange={(files) => form.setData('evidence', files)}
                            error={form.errors.evidence}
                        />
                    </section>

                    <div className="flex justify-end">
                        <button
                            disabled={form.processing}
                            className="rounded-lg bg-emerald-800 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            {form.processing
                                ? 'Submitting complaint…'
                                : 'Submit walk-in complaint (no case created)'}
                        </button>
                    </div>
                </form>
            </main>
        </LuponLayout>
    );
}
