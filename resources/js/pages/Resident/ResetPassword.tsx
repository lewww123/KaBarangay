import { Head, useForm } from '@inertiajs/react';
import { LogoMark } from '@/components/Logo';

interface Props {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/resident/reset-password');
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
            <Head title="Reset Password" />

            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <LogoMark size="lg" className="mx-auto" />
                    <h1 className="mt-4 text-xl font-semibold tracking-tight text-ink-strong">
                        Reset Password
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Choose a new password for your resident account.
                    </p>
                </div>

                <div className="rounded-xl border border-line-soft bg-white p-6 shadow-card sm:p-7">
                    {errors.email && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-700">
                            {errors.email}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <input
                            type="hidden"
                            name="token"
                            value={data.token}
                            readOnly
                        />

                        <div>
                            <label
                                htmlFor="reset-account-email"
                                className="block text-xs font-semibold text-ink"
                            >
                                Email Address
                            </label>
                            <input
                                id="reset-account-email"
                                type="email"
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                                className="mt-1 w-full rounded-lg border border-line px-3 py-2.5 text-sm outline-none focus:border-brand focus:ring-1 focus:ring-brand"
                                required
                            />
                        </div>

                        <div>
                            <label
                                htmlFor="reset-password"
                                className="block text-xs font-semibold text-ink"
                            >
                                New Password
                            </label>
                            <input
                                id="reset-password"
                                type="password"
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                                className="mt-1 w-full rounded-lg border border-line px-3 py-2.5 text-sm outline-none focus:border-brand focus:ring-1 focus:ring-brand"
                                minLength={8}
                                required
                            />
                            {errors.password && (
                                <p className="mt-1 text-xs text-red-500">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="reset-password-confirmation"
                                className="block text-xs font-semibold text-ink"
                            >
                                Confirm New Password
                            </label>
                            <input
                                id="reset-password-confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) =>
                                    setData(
                                        'password_confirmation',
                                        e.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded-lg border border-line px-3 py-2.5 text-sm outline-none focus:border-brand focus:ring-1 focus:ring-brand"
                                minLength={8}
                                required
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-lg bg-brand py-2.5 text-sm font-semibold text-white transition hover:bg-brand-deep disabled:opacity-60"
                        >
                            {processing ? 'Saving...' : 'Reset Password'}
                        </button>
                    </form>

                    <p className="mt-5 text-center text-xs text-ink-muted">
                        <a
                            href="/resident/login"
                            className="font-semibold text-brand hover:underline"
                        >
                            Back to login
                        </a>
                    </p>
                </div>
            </div>
        </div>
    );
}
