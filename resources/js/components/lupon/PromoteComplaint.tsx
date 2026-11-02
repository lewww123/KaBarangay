import { useForm } from '@inertiajs/react';
import type { LuponOfficials } from './types';
import { Errors } from './WorkflowPanel';
export default function PromoteComplaint({
    complaintId,
    officials,
}: {
    complaintId: number;
    officials: LuponOfficials;
}) {
    const form = useForm({
        hearing_date: '',
        hearing_time: '',
        venue: 'Barangay Hall',
        acting_captain_id: '',
    });
    return (
        <form
            className="w-full space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/lupon/complaints/${complaintId}/promote`);
            }}
        >
            <h3 className="font-bold text-emerald-950">
                Docket accepted complaint
            </h3>
            <p className="text-xs text-slate-600">
                Set the first meeting to prepare the Notice of Hearing and
                Summons. Record actual service in the case record.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm">
                    Hearing date
                    <input
                        required
                        type="date"
                        value={form.data.hearing_date}
                        onChange={(e) =>
                            form.setData('hearing_date', e.target.value)
                        }
                        className="mt-1 block w-full rounded-lg border p-2"
                    />
                </label>
                <label className="text-sm">
                    Time
                    <input
                        required
                        type="time"
                        value={form.data.hearing_time}
                        onChange={(e) =>
                            form.setData('hearing_time', e.target.value)
                        }
                        className="mt-1 block w-full rounded-lg border p-2"
                    />
                </label>
            </div>
            <label className="block text-sm">
                Venue
                <input
                    required
                    value={form.data.venue}
                    onChange={(e) => form.setData('venue', e.target.value)}
                    className="mt-1 block w-full rounded-lg border p-2"
                />
            </label>
            <label className="block text-sm">
                Acting Punong Barangay (optional)
                <select
                    value={form.data.acting_captain_id}
                    onChange={(e) =>
                        form.setData('acting_captain_id', e.target.value)
                    }
                    className="mt-1 block w-full rounded-lg border bg-white p-2"
                >
                    <option value="">Default Punong Barangay</option>
                    {officials.active_kagawads.map((member) => (
                        <option key={member.id} value={member.id}>
                            {member.name}
                        </option>
                    ))}
                </select>
            </label>
            <Errors errors={form.errors} />
            <button
                disabled={form.processing}
                className="rounded-lg bg-emerald-800 px-4 py-2 font-semibold text-white disabled:opacity-50"
            >
                Docket case & prepare notices
            </button>
        </form>
    );
}
