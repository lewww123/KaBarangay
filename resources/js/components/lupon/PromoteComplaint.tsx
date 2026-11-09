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
        payment_date: '',
        verification_note: '',
        payment_verified: false,
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
                if (!window.confirm('Confirm that you personally verified the actual payment and official receipt. Create the official case?')) return;
                form.post(`/lupon/complaints/${complaintId}/promote`, {
                    only: ['complaints', 'stats', 'flash', 'errors'],
                    preserveState: true,
                    preserveScroll: true,
                    showProgress: false,
                });
            }}
        >
            <h3 className="font-semibold text-emerald-950">
                Verify payment and create the official case
            </h3>
            <p className="text-xs text-ink-muted">
                Record the actual barangay payment and official receipt, then
                optionally an agreed hearing schedule. The case is created only
                when this is submitted.
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
            <label className="block text-sm">Actual payment date<input required type="date" value={form.data.payment_date} onChange={e => form.setData('payment_date', e.target.value)} className="mt-1 block w-full rounded-lg border p-2" /></label>
            <label className="block text-sm">Verification details<textarea required maxLength={2000} value={form.data.verification_note} onChange={e => form.setData('verification_note', e.target.value)} className="mt-1 block w-full rounded-lg border p-2" placeholder="Record how you checked the actual payment and official receipt." /></label>
            <label className="flex gap-2 text-sm"><input required type="checkbox" checked={form.data.payment_verified} onChange={e => form.setData('payment_verified', e.target.checked)} />I personally verified the actual barangay payment and official receipt. An OR number alone is not proof of payment.</label>
            <Errors errors={form.errors} />
            <button
                disabled={form.processing}
                className="rounded-lg bg-emerald-800 px-4 py-2 font-semibold text-white disabled:opacity-50"
            >
                {form.processing
                    ? 'Creating official case…'
                    : 'Create Official Case'}
            </button>
        </form>
    );
}
