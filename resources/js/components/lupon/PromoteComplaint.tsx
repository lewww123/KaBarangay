import { useForm } from '@inertiajs/react';
import type { LuponOfficials } from './types';
import { Errors } from './WorkflowPanel';
export default function PromoteComplaint({
    complaintId,
    officials,
    assessedFee,
}: {
    complaintId: number;
    officials: LuponOfficials;
    assessedFee: string | number;
}) {
    const form = useForm({
        hearing_date: '',
        hearing_time: '',
        venue: 'Barangay Hall',
        acting_captain_id: '',
        or_number: '',
    });
    return (
        <form
            className="w-full space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/lupon/complaints/${complaintId}/promote`, {
                    only: ['complaints', 'stats', 'flash', 'errors'],
                    preserveState: true,
                    preserveScroll: true,
                    showProgress: false,
                });
            }}
        >
            <h3 className="font-bold text-emerald-950">
                Record payment and create case
            </h3>
            <p className="text-xs text-slate-600">
                Confirm the actual barangay payment and official receipt, then
                schedule Kapitan hearing #1. The case is created only when this
                is saved.
            </p>
            <p className="text-sm font-semibold">
                Assessed filing fee: ₱
                {Number(assessedFee).toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                })}
            </p>
            <label className="block text-sm">
                Official receipt (OR) number
                <input
                    required
                    maxLength={100}
                    value={form.data.or_number}
                    onChange={(e) => form.setData('or_number', e.target.value)}
                    className="mt-1 block w-full rounded-lg border p-2"
                />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm">
                    Hearing date
                    <input
                        required
                        type="date"
                        min={new Date().toLocaleDateString('en-CA', {
                            timeZone: 'Asia/Manila',
                        })}
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
                {form.processing
                    ? 'Recording payment and creating case…'
                    : 'Confirm payment, save OR & create case'}
            </button>
        </form>
    );
}
