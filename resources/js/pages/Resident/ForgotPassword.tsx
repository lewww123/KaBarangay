import { Head, useForm, usePage } from '@inertiajs/react';
import { LogoMark } from '@/components/Logo';

export default function ForgotPassword() {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/resident/forgot-password', {
            onSuccess: () => reset('email'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
            <Head title="Forgot Password" />

            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <LogoMark size="lg" className="mx-auto" />
                    <h1 className="mt-4 text-xl font-semibold tracking-tight text-ink-strong">
                        Forgot Password
                    </h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Enter the email address on your resident account and we
                        will send you a reset link.
                    </p>
                </div>

                <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-7">
                    {flash?.success && (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
                            {flash.success}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label
                                htmlFor="reset-email"
                                className="block text-xs font-semibold text-ink"
                            >
                                Email Address
                            </label>
                            <input
                                id="reset-email"
                                type="email"
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                                className="mt-1 w-full rounded-lg border border-line px-3 py-2.5 text-sm outline-none focus:border-brand focus:ring-1 focus:ring-brand"
                                placeholder="you@example.com"
                                required
                            />
                            {errors.email && (
                                <p className="mt-1 text-xs text-red-500">
                                    {errors.email}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-lg bg-brand py-2.5 text-sm font-semibold text-white transition hover:bg-brand-deep disabled:opacity-60"
                        >
                            {processing ? 'Sending...' : 'Send Reset Link'}
                        </button>
                    </form>

                    <p className="mt-5 text-center text-xs text-ink-muted">
                        Remembered your password?{' '}
                        <a
                            href="/resident/login"
                            className="font-semibold text-brand hover:underline"
                        >
                            Back to login
                        </a>
                    </p>
                </div>

                <p className="mt-4 text-center text-[11px] leading-5 text-ink-faint">
                    For security, this page does not confirm whether an account
                    exists.
                </p>
            </div>
        </div>
    );
}
