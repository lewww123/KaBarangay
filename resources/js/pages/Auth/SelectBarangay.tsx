import { Deferred, Head, router } from '@inertiajs/react';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import { FormEvent, useState } from 'react';

interface Barangay {
    id: number;
    name: string;
    code: string;
}

interface Props {
    barangays: Barangay[];
}

export default function SelectBarangay(props: Props) {
    return (
        <Deferred
            data="barangays"
            fallback={
                <main className="mx-auto max-w-xl p-6">
                    <PortalPageSkeleton list rows={3} />
                </main>
            }
        >
            <SelectBarangayContent {...props} />
        </Deferred>
    );
}

function SelectBarangayContent({ barangays }: Props) {
    const [selectedBarangay, setSelectedBarangay] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!selectedBarangay) {
            return;
        }

        setProcessing(true);

        router.post(
            '/select-barangay',
            {
                barangay_id: Number(selectedBarangay),
            },
            {
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Select Barangay" />

            <main className="min-h-screen bg-canvas">
                <div className="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
                    <div className="w-full max-w-md">
                        {/* Logo / Brand */}
                        <div className="mb-8 text-center">
                            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-xl bg-blue-600 shadow-lg">
                                <span className="text-2xl font-semibold text-white">
                                    K
                                </span>
                            </div>

                            <h1 className="text-3xl font-semibold tracking-tight text-ink-strong">
                                KaBarangay
                            </h1>

                            <p className="mt-2 text-sm text-ink-muted">
                                Barangay Management System
                            </p>
                        </div>

                        {/* Card */}
                        <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-8">
                            <div className="mb-7">
                                <h2 className="text-xl font-semibold text-ink-strong">
                                    Select your barangay
                                </h2>

                                <p className="mt-2 text-sm leading-6 text-ink-muted">
                                    Choose the barangay you are assigned to
                                    before continuing to staff login.
                                </p>
                            </div>

                            <form onSubmit={submit}>
                                <label
                                    htmlFor="barangay"
                                    className="mb-2 block text-sm font-medium text-ink"
                                >
                                    Barangay
                                </label>

                                <select
                                    id="barangay"
                                    value={selectedBarangay}
                                    onChange={(event) =>
                                        setSelectedBarangay(event.target.value)
                                    }
                                    disabled={processing}
                                    className="w-full rounded-xl border border-line bg-white px-4 py-3.5 text-sm text-ink-strong transition outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-secondary"
                                >
                                    <option value="">Select a barangay</option>

                                    {barangays.map((barangay) => (
                                        <option
                                            key={barangay.id}
                                            value={barangay.id}
                                        >
                                            {barangay.name}
                                        </option>
                                    ))}
                                </select>

                                {/* Empty state */}
                                {barangays.length === 0 && (
                                    <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">
                                        No active barangays are currently
                                        available.
                                    </p>
                                )}

                                <button
                                    type="submit"
                                    disabled={!selectedBarangay || processing}
                                    className="mt-6 w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-card transition hover:bg-blue-700 focus:ring-4 focus:ring-blue-100 focus:outline-none disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    {processing ? 'Continuing...' : 'Continue'}
                                </button>
                            </form>
                        </div>

                        {/* Footer */}
                        <p className="mt-6 text-center text-xs text-ink-faint">
                            Authorized personnel only
                        </p>
                    </div>
                </div>
            </main>
        </>
    );
}
