import { Head, useForm, usePage } from '@inertiajs/react';

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
        <div className="flex min-h-screen items-center justify-center bg-[#f7f6f0] px-4 py-10">
            <Head title="Forgot Password" />

            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-white text-lg font-bold text-[#3f6f3d] shadow-sm">
                        SJ
                    </div>
                    <h1 className="mt-4 text-xl font-bold text-gray-900">
                        Forgot Password
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Enter the email address on your resident account and we
                        will send you a reset link.
                    </p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-7">
                    {flash?.success && (
                        <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-800">
                            {flash.success}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label
                                htmlFor="reset-email"
                                className="block text-xs font-bold text-gray-700"
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
                                className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d]"
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
                            className="w-full rounded-lg bg-[#3f6f3d] py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30] disabled:opacity-60"
                        >
                            {processing ? 'Sending...' : 'Send Reset Link'}
                        </button>
                    </form>

                    <p className="mt-5 text-center text-xs text-gray-500">
                        Remembered your password?{' '}
                        <a
                            href="/resident/login"
                            className="font-semibold text-[#3f6f3d] hover:underline"
                        >
                            Back to login
                        </a>
                    </p>
                </div>

                <p className="mt-4 text-center text-[11px] leading-5 text-gray-400">
                    For security, this page does not confirm whether an account
                    exists.
                </p>
            </div>
        </div>
    );
}
