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
        department?: string;
        username?: string;
        password?: string;
        login?: string;
    };
}

const departments = [
    {
        value: 'admin',
        label: 'Admin',
        description: 'Barangay administration and management',
    },
    {
        value: 'lupon',
        label: 'Lupon',
        description: 'Lupon Tagapamayapa services',
    },
    {
        value: 'senior',
        label: 'Senior',
        description: 'Senior citizen services',
    },
    {
        value: 'pwd',
        label: 'PWD',
        description: 'Persons with disability services',
    },
    {
        value: 'certificate',
        label: 'Certificate',
        description: 'Barangay certificate services',
    },
];

export default function DepartmentLogin({
    barangay,
    errors = {},
}: Props) {
    const [department, setDepartment] = useState('');
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!department || !username || !password) {
            return;
        }

        setProcessing(true);

        router.post(
            '/department-login',
            {
                department,
                username,
                password,
            },
            {
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Department Login" />

            <main className="min-h-screen bg-slate-50">
                <div className="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
                    <div className="w-full max-w-md">

                        {/* Brand */}
                        <div className="mb-8 text-center">
                            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-600 shadow-lg">
                                <span className="text-2xl font-bold text-white">
                                    K
                                </span>
                            </div>

                            <h1 className="text-3xl font-bold tracking-tight text-slate-900">
                                KaBarangay
                            </h1>

                            <p className="mt-2 text-sm text-slate-500">
                                Barangay Management System
                            </p>
                        </div>

                        {/* Card */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">

                            <div className="mb-6">
                                <h2 className="text-xl font-semibold text-slate-900">
                                    Department Login
                                </h2>

                                <p className="mt-2 text-sm leading-6 text-slate-500">
                                    Select your department and enter your
                                    credentials to continue.
                                </p>
                            </div>

                            {/* Selected Barangay */}
                            <div className="mb-6 rounded-xl bg-blue-50 p-4">
                                <p className="text-xs font-medium uppercase tracking-wide text-blue-600">
                                    Selected Barangay
                                </p>

                                <p className="mt-1 font-semibold text-blue-900">
                                    {barangay.name}
                                </p>

                                <button
                                    type="button"
                                    onClick={() => router.get('/')}
                                    disabled={processing}
                                    className="mt-2 text-xs font-medium text-blue-600 hover:underline disabled:opacity-50"
                                >
                                    Change Barangay
                                </button>
                            </div>

                            <form onSubmit={submit} className="space-y-5">

                                {/* Department */}
                                <div>
                                    <label
                                        htmlFor="department"
                                        className="mb-2 block text-sm font-medium text-slate-700"
                                    >
                                        Department
                                    </label>

                                    <select
                                        id="department"
                                        value={department}
                                        onChange={(event) =>
                                            setDepartment(event.target.value)
                                        }
                                        disabled={processing}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3.5 text-sm text-slate-900 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100"
                                        required
                                    >
                                        <option value="">
                                            Select a department
                                        </option>

                                        {departments.map((item) => (
                                            <option
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>

                                    {department && (
                                        <p className="mt-2 text-xs text-slate-500">
                                            {
                                                departments.find(
                                                    (item) =>
                                                        item.value ===
                                                        department,
                                                )?.description
                                            }
                                        </p>
                                    )}

                                    {errors.department && (
                                        <p className="mt-2 text-sm text-red-600">
                                            {errors.department}
                                        </p>
                                    )}
                                </div>

                                {/* Username */}
                                <div>
                                    <label
                                        htmlFor="username"
                                        className="mb-2 block text-sm font-medium text-slate-700"
                                    >
                                        Username
                                    </label>

                                    <input
                                        id="username"
                                        type="text"
                                        value={username}
                                        onChange={(event) =>
                                            setUsername(event.target.value)
                                        }
                                        placeholder="Enter your username"
                                        autoComplete="username"
                                        disabled={processing}
                                        className="w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:bg-slate-100"
                                        required
                                    />

                                    {errors.username && (
                                        <p className="mt-2 text-sm text-red-600">
                                            {errors.username}
                                        </p>
                                    )}
                                </div>

                                {/* Password */}
                                <div>
                                    <label
                                        htmlFor="password"
                                        className="mb-2 block text-sm font-medium text-slate-700"
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
                                        disabled={processing}
                                        className="w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:bg-slate-100"
                                        required
                                    />

                                    {errors.password && (
                                        <p className="mt-2 text-sm text-red-600">
                                            {errors.password}
                                        </p>
                                    )}
                                </div>

                                {errors.login && (
                                    <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">
                                        {errors.login}
                                    </div>
                                )}

                                {/* Continue */}
                                <button
                                    type="submit"
                                    disabled={
                                        !department ||
                                        !username ||
                                        !password ||
                                        processing
                                    }
                                    className="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    {processing
                                        ? 'Verifying...'
                                        : 'Continue'}
                                </button>
                            </form>
                        </div>

                        <p className="mt-6 text-center text-xs text-slate-400">
                            Authorized personnel only
                        </p>
                    </div>
                </div>
            </main>
        </>
    );
}