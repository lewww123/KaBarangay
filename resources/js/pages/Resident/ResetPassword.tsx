import { Head, useForm } from '@inertiajs/react';

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
        <div className="flex min-h-screen items-center justify-center bg-[#f7f6f0] px-4 py-10">
            <Head title="Reset Password" />

            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-white text-lg font-bold text-[#3f6f3d] shadow-sm">
                        SJ
                    </div>
                    <h1 className="mt-4 text-xl font-bold text-gray-900">Reset Password</h1>
                    <p className="mt-1 text-sm text-gray-500">Choose a new password for your resident account.</p>
                </div>

                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-7">
                    {errors.email && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-700">
                            {errors.email}
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <input type="hidden" name="token" value={data.token} readOnly />

                        <div>
                            <label htmlFor="reset-account-email" className="block text-xs font-bold text-gray-700">
                                Email Address
                            </label>
                            <input
                                id="reset-account-email"
                                type="email"
                                autoComplete="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d]"
                                required
                            />
                        </div>

                        <div>
                            <label htmlFor="reset-password" className="block text-xs font-bold text-gray-700">
                                New Password
                            </label>
                            <input
                                id="reset-password"
                                type="password"
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d]"
                                minLength={8}
                                required
                            />
                            {errors.password && <p className="mt-1 text-xs text-red-500">{errors.password}</p>}
                        </div>

                        <div>
                            <label htmlFor="reset-password-confirmation" className="block text-xs font-bold text-gray-700">
                                Confirm New Password
                            </label>
                            <input
                                id="reset-password-confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d]"
                                minLength={8}
                                required
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-lg bg-[#3f6f3d] py-2.5 text-sm font-semibold text-white transition hover:bg-[#315b30] disabled:opacity-60"
                        >
                            {processing ? 'Saving...' : 'Reset Password'}
                        </button>
                    </form>

                    <p className="mt-5 text-center text-xs text-gray-500">
                        <a href="/resident/login" className="font-semibold text-[#3f6f3d] hover:underline">
                            Back to login
                        </a>
                    </p>
                </div>
            </div>
        </div>
    );
}
