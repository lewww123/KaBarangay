import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Props = {
    feedback?: string | null;
};

export default function RegistrationVerificationResend({ feedback }: Props) {
    const form = useForm({ reference: '', password: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/resident/register/verification/resend', {
            preserveScroll: true,
            onFinish: () => form.setData('password', ''),
        });
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
            <Head title="Resend Registration Verification" />
            <main className="w-full max-w-md rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-8">
                <Link
                    href="/resident/register"
                    className="text-sm font-semibold text-brand hover:underline"
                >
                    KaBarangay Resident Registration
                </Link>
                <h1 className="mt-5 text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                    Request a verification code
                </h1>
                <p className="mt-2 text-sm leading-6 text-ink-muted">
                    Enter the application reference and the password created
                    during registration. For privacy, the same response is shown
                    whether or not a message can be sent.
                </p>

                {feedback && (
                    <p
                        role="status"
                        className="mt-5 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800"
                    >
                        {feedback}
                    </p>
                )}

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <div>
                        <label
                            htmlFor="registration-reference"
                            className="mb-1 block text-sm font-medium text-ink"
                        >
                            Application reference
                        </label>
                        <input
                            id="registration-reference"
                            type="text"
                            autoComplete="off"
                            value={form.data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            className="min-h-11 w-full rounded-lg border border-line px-3 py-2 font-mono text-sm focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="registration-password"
                            className="mb-1 block text-sm font-medium text-ink"
                        >
                            Registration password
                        </label>
                        <input
                            id="registration-password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                            className="min-h-11 w-full rounded-lg border border-line px-3 py-2 text-sm focus:border-brand focus:ring-2 focus:ring-brand/20 focus:outline-none"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="min-h-11 w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing
                            ? 'Submitting…'
                            : 'Request verification code'}
                    </button>
                </form>

                <p className="mt-4 text-xs leading-5 text-ink-muted">
                    Email OTP verification is required for Resident
                    registration. Request a fresh code if your application email
                    needs to be verified again.
                </p>
            </main>
        </div>
    );
}
