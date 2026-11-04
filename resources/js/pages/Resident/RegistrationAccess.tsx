import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Props = {
    reference?: string;
    feedback?: string | null;
};

export default function RegistrationAccess({
    reference = '',
    feedback,
}: Props) {
    const form = useForm({ reference, password: '' });
    const credentialsError = (form.errors as Record<string, string | undefined>)
        .credentials;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/resident/application/access', {
            preserveScroll: true,
            onFinish: () => form.setData('password', ''),
        });
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-[#f7f6f0] px-4 py-10">
            <Head title="Access Your Resident Application" />
            <main className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                <Link
                    href="/resident/register"
                    className="text-sm font-semibold text-[#3f6f3d] hover:underline"
                >
                    KaBarangay Resident Registration
                </Link>
                <h1 className="mt-5 text-2xl font-bold text-gray-900">
                    Access your application
                </h1>
                <p className="mt-2 text-sm leading-6 text-gray-600">
                    Enter the application reference and the password you created
                    during registration. The reference alone does not grant
                    access.
                </p>

                {feedback && (
                    <p
                        role="status"
                        className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
                    >
                        {feedback}
                    </p>
                )}

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <div>
                        <label
                            htmlFor="application-reference"
                            className="mb-1 block text-sm font-medium text-gray-700"
                        >
                            Application reference
                        </label>
                        <input
                            id="application-reference"
                            type="text"
                            autoComplete="off"
                            value={form.data.reference}
                            onChange={(event) =>
                                form.setData('reference', event.target.value)
                            }
                            aria-invalid={Boolean(credentialsError)}
                            className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/20 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label
                            htmlFor="application-password"
                            className="mb-1 block text-sm font-medium text-gray-700"
                        >
                            Registration password
                        </label>
                        <input
                            id="application-password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(event) =>
                                form.setData('password', event.target.value)
                            }
                            aria-invalid={Boolean(credentialsError)}
                            className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/20 focus:outline-none"
                        />
                    </div>

                    {credentialsError && (
                        <p
                            role="alert"
                            className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                        >
                            {credentialsError}
                        </p>
                    )}

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="min-h-11 w-full rounded-lg bg-[#3f6f3d] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? 'Signing in…' : 'View application'}
                    </button>
                </form>

                <p className="mt-4 text-xs leading-5 text-gray-500">
                    Access expires after 15 minutes of inactivity and at most 2
                    hours after sign-in. This page is read-only.
                </p>
            </main>
        </div>
    );
}
