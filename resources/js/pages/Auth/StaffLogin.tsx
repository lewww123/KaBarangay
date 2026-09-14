import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface Barangay {
    id: number;
    name: string;
    code: string;
}

interface Props {
    barangay: Barangay;
    department: string;
    errors?: {
        staff_id?: string;
        full_name?: string;
        password?: string;
        login?: string;
    };
}

const departmentLabels: Record<string, string> = {
    admin: 'Admin',
    lupon: 'Lupon',
    senior: 'Senior',
    pwd: 'PWD',
    certificate: 'Certificate',
};

export default function StaffLogin({
    barangay,
    department,
    errors = {},
}: Props) {
    const [staffId, setStaffId] = useState('');
    const [fullName, setFullName] = useState('');
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!staffId || !fullName || !password) {
            return;
        }

        setProcessing(true);

        router.post(
            '/staff-login',
            {
                staff_id: staffId,
                full_name: fullName,
                password,
            },
            {
                onFinish: () => setProcessing(false),
            },
        );
    };

    const goBack = () => {
        router.get('/department-login');
    };

    return (
        <>
            <Head title="Staff Login" />

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
                                    Staff Login
                                </h2>

                                <p className="mt-2 text-sm leading-6 text-slate-500">
                                    Enter your staff credentials to access
                                    KaBarangay.
                                </p>
                            </div>

                            {/* Access Information */}
                            <div className="mb-6 space-y-3 rounded-xl bg-slate-50 p-4">

                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                        Barangay
                                    </p>

                                    <p className="mt-1 text-sm font-semibold text-slate-800">
                                        {barangay.name}
                                    </p>
                                </div>

                                <div>
                                    <p className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                        Department
                                    </p>

                                    <p className="mt-1 text-sm font-semibold text-slate-800">
                                        {departmentLabels[department] ??
                                            department}
                                    </p>
                                </div>
                            </div>

                            <form onSubmit={submit} className="space-y-5">

                                {/* Staff ID */}
                                <div>
                                    <label
                                        htmlFor="staff_id"
                                        className="mb-2 block text-sm font-medium text-slate-700"
                                    >
                                        Staff ID
                                    </label>

                                    <input
                                        id="staff_id"
                                        type="text"
                                        value={staffId}
                                        onChange={(event) =>
                                            setStaffId(event.target.value)
                                        }
                                        placeholder="Enter your staff ID"
                                        autoComplete="username"
                                        disabled={processing}
                                        className="w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:bg-slate-100"
                                        required
                                    />

                                    {errors.staff_id && (
                                        <p className="mt-2 text-sm text-red-600">
                                            {errors.staff_id}
                                        </p>
                                    )}
                                </div>

                                {/* Full Name */}
                                <div>
                                    <label
                                        htmlFor="full_name"
                                        className="mb-2 block text-sm font-medium text-slate-700"
                                    >
                                        Full Name
                                    </label>

                                    <input
                                        id="full_name"
                                        type="text"
                                        value={fullName}
                                        onChange={(event) =>
                                            setFullName(event.target.value)
                                        }
                                        placeholder="Enter your full name"
                                        autoComplete="name"
                                        disabled={processing}
                                        className="w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:bg-slate-100"
                                        required
                                    />

                                    {errors.full_name && (
                                        <p className="mt-2 text-sm text-red-600">
                                            {errors.full_name}
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

                                {/* General Login Error */}
                                {errors.login && (
                                    <div className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">
                                        {errors.login}
                                    </div>
                                )}

                                {/* Buttons */}
                                <div className="flex flex-col-reverse gap-3 pt-2 sm:flex-row">

                                    <button
                                        type="button"
                                        onClick={goBack}
                                        disabled={processing}
                                        className="w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Back
                                    </button>

                                    <button
                                        type="submit"
                                        disabled={
                                            !staffId ||
                                            !fullName ||
                                            !password ||
                                            processing
                                        }
                                        className="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-300"
                                    >
                                        {processing
                                            ? 'Logging in...'
                                            : 'Log In'}
                                    </button>

                                </div>
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