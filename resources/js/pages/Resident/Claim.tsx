import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Claim() {
    const { data, setData, post, processing, errors, reset } = useForm({
        resident_number: '',
        qr_reference: '',
        last_name: '',
        birthdate: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const claimErrors = errors as typeof errors & { identity?: string };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        post('/resident/claim', {
            onSuccess: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <div className="min-h-screen bg-[#f7f6f0] px-4 py-10">
            <Head title="Claim Resident Account" />

            <div className="mx-auto w-full max-w-2xl">
                <div className="mb-6 text-center">
                    <Link
                        href="/resident/login"
                        className="inline-flex items-center gap-3"
                    >
                        <div className="flex h-12 w-12 items-center justify-center rounded-full bg-white text-sm font-bold text-[#3f6f3d] shadow-sm">
                            KB
                        </div>
                        <div className="text-left">
                            <p className="text-base font-bold text-gray-900">
                                KaBarangay
                            </p>
                            <p className="text-xs text-gray-500">
                                Resident Portal
                            </p>
                        </div>
                    </Link>

                    <h1 className="mt-5 text-2xl font-bold text-gray-900">
                        Claim Your Resident Account
                    </h1>
                    <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-gray-600">
                        Use the Resident number and QR code issued by your
                        barangay. A barangay administrator will review the claim
                        before the account is linked.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-7"
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field
                            label="Resident Number"
                            error={errors.resident_number}
                        >
                            <input
                                value={data.resident_number}
                                onChange={(event) =>
                                    setData(
                                        'resident_number',
                                        event.target.value,
                                    )
                                }
                                placeholder="BRGY-RES-000001"
                                className="input"
                                required
                            />
                        </Field>

                        <Field label="Last Name" error={errors.last_name}>
                            <input
                                value={data.last_name}
                                onChange={(event) =>
                                    setData('last_name', event.target.value)
                                }
                                className="input"
                                required
                            />
                        </Field>

                        <Field label="Birthdate" error={errors.birthdate}>
                            <input
                                type="date"
                                value={data.birthdate}
                                onChange={(event) =>
                                    setData('birthdate', event.target.value)
                                }
                                className="input"
                                required
                            />
                        </Field>

                        <Field label="Registered Email" error={errors.email}>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(event) =>
                                    setData('email', event.target.value)
                                }
                                className="input"
                                required
                            />
                        </Field>
                    </div>

                    <div className="mt-4">
                        <Field label="QR Reference" error={errors.qr_reference}>
                            <textarea
                                value={data.qr_reference}
                                onChange={(event) =>
                                    setData('qr_reference', event.target.value)
                                }
                                rows={3}
                                placeholder="Scan the QR code or paste the verification URL"
                                className="input font-mono text-xs"
                                required
                            />
                        </Field>
                    </div>

                    {claimErrors.identity && (
                        <p className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700">
                            {claimErrors.identity}
                        </p>
                    )}

                    <div className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Password" error={errors.password}>
                            <input
                                type="password"
                                value={data.password}
                                onChange={(event) =>
                                    setData('password', event.target.value)
                                }
                                autoComplete="new-password"
                                className="input"
                                required
                            />
                        </Field>
                        <Field
                            label="Confirm Password"
                            error={errors.password_confirmation}
                        >
                            <input
                                type="password"
                                value={data.password_confirmation}
                                onChange={(event) =>
                                    setData(
                                        'password_confirmation',
                                        event.target.value,
                                    )
                                }
                                autoComplete="new-password"
                                className="input"
                                required
                            />
                        </Field>
                    </div>

                    <p className="mt-4 text-xs leading-5 text-gray-500">
                        Use at least 8 characters with letters and numbers. The
                        password is only used after approval, so it will not
                        activate an account immediately.
                    </p>

                    <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <Link
                            href="/resident/login"
                            className="text-sm font-semibold text-[#3f6f3d] hover:underline"
                        >
                            Back to login
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-[#3f6f3d] px-5 py-3 text-sm font-bold text-white hover:bg-[#315b30] disabled:opacity-60"
                        >
                            {processing ? 'Submitting...' : 'Submit Claim'}
                        </button>
                    </div>
                </form>

                <p className="mt-4 text-center text-[11px] leading-5 text-gray-400">
                    Do not share your QR code. The QR code identifies the
                    Resident record but does not replace the identity check and
                    administrator review.
                </p>
            </div>
        </div>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="block text-xs font-bold text-gray-700">
                {label}
            </label>
            <div className="mt-1 [&_.input]:w-full [&_.input]:rounded-lg [&_.input]:border [&_.input]:border-gray-300 [&_.input]:px-3 [&_.input]:py-2.5 [&_.input]:text-sm [&_.input]:outline-none [&_.input]:focus:border-[#3f6f3d] [&_.input]:focus:ring-1 [&_.input]:focus:ring-[#3f6f3d]">
                {children}
            </div>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}
