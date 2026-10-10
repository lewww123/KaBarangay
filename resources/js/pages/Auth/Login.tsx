import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface Barangay {
    id: number;
    name: string;
    code: string;
}

interface Props {
    barangay: Barangay;
    errors?: {
        login?: string;
        password?: string;
    };
}

export default function Login({ barangay, errors = {} }: Props) {
    const [login, setLogin] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setProcessing(true);

        router.post(
            '/login',
            {
                login,
                password,
                remember,
            },
            {
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Staff Login" />

            <main className="flex min-h-screen items-center justify-center bg-gray-100 px-4 py-8">
                <section className="w-full max-w-md rounded-2xl bg-white p-6 shadow-lg sm:p-8">

                    <div className="mb-8 text-center">
                        <h1 className="text-3xl font-bold text-gray-900">
                            KaBarangay
                        </h1>

                        <p className="mt-2 text-sm text-gray-500">
                            Barangay Management System
                        </p>
                    </div>

                    <div className="mb-6">
                        <h2 className="text-xl font-semibold text-gray-900">
                            Staff Login
                        </h2>

                        <p className="mt-1 text-sm text-gray-500">
                            Sign in to access your barangay management system.
                        </p>
                    </div>

                    <div className="mb-6 rounded-lg bg-blue-50 p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-blue-600">
                            Selected Barangay
                        </p>

                        <p className="mt-1 font-semibold text-blue-900">
                            {barangay.name}
                        </p>

                        <button
                            type="button"
                            onClick={() => router.get('/')}
                            className="mt-2 text-xs font-medium text-blue-600 hover:underline"
                        >
                            Change Barangay
                        </button>
                    </div>

                    <form onSubmit={submit} className="space-y-5">

                        <div>
                            <label
                                htmlFor="login"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Staff ID or Email
                            </label>

                            <input
                                id="login"
                                type="text"
                                value={login}
                                onChange={(event) =>
                                    setLogin(event.target.value)
                                }
                                placeholder="Enter Staff ID or Email"
                                autoComplete="username"
                                className="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                required
                            />

                            {errors.login && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.login}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="password"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Password
                            </label>

                            <input
                                id="password"
                                type="password"
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                placeholder="Enter your password"
                                autoComplete="current-password"
                                className="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                required
                            />

                            {errors.password && (
                                <p className="mt-2 text-sm text-red-600">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center">
                            <input
                                id="remember"
                                type="checkbox"
                                checked={remember}
                                onChange={(event) =>
                                    setRemember(event.target.checked)
                                }
                                className="h-4 w-4 rounded border-gray-300"
                            />

                            <label
                                htmlFor="remember"
                                className="ml-2 text-sm text-gray-600"
                            >
                                Remember me
                            </label>
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full rounded-lg bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing ? 'Signing in...' : 'Login'}
                        </button>
                    </form>

                    <p className="mt-6 text-center text-xs text-gray-400">
                        Authorized personnel only
                    </p>
                </section>
            </main>
        </>
    );
}