import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

type Barangay = { id: number; name: string };
type Props = { barangays: Barangay[]; errors?: Record<string, string> };

const positions: Record<string, string[]> = {
    lupon: ['Lupon Secretary', 'Lupon Staff / Member', 'Punong Barangay'],
    clerk: ['Barangay Clerk', 'Document Officer'],
    admin: ['Punong Barangay', 'Barangay Secretary', 'Barangay Treasurer'],
    pwd: ['PWD Affairs Officer'],
    senior: ['OSCA Head'],
};

export default function StaffLogin({ barangays, errors = {} }: Props) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing } = useForm({
        barangay_id: '',
        department: '',
        position: '',
        full_name: '',
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/login');
    };

    return (
        <main className="flex min-h-screen items-center justify-center bg-stone-100 p-4">
            <Head title="Unified Staff Login" />
            <form
                onSubmit={submit}
                className="w-full max-w-md space-y-5 rounded-2xl bg-white p-7 shadow-xl"
            >
                <div>
                    <p className="text-xs font-bold tracking-[0.2em] text-[#3f6f3d] uppercase">
                        KaBarangay
                    </p>
                    <h1 className="mt-2 text-2xl font-bold text-gray-900">
                        Unified Staff Login
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Use your registered resident account and staff
                        assignment.
                    </p>
                </div>
                <Field label="Barangay">
                    <select
                        required
                        value={data.barangay_id}
                        onChange={(event) =>
                            setData('barangay_id', event.target.value)
                        }
                    >
                        <option value="">Select barangay</option>
                        {barangays.map((barangay) => (
                            <option key={barangay.id} value={barangay.id}>
                                {barangay.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Department">
                    <select
                        required
                        value={data.department}
                        onChange={(event) => {
                            setData('department', event.target.value);
                            setData('position', '');
                        }}
                    >
                        <option value="">Select department</option>
                        <option value="lupon">Lupon</option>
                        <option value="clerk">Clerk</option>
                        <option value="admin">Admin</option>
                        <option value="pwd">PWD</option>
                        <option value="senior">Senior</option>
                    </select>
                </Field>
                <Field label="Position">
                    <select
                        required
                        disabled={!data.department}
                        value={data.position}
                        onChange={(event) =>
                            setData('position', event.target.value)
                        }
                    >
                        <option value="">Select position</option>
                        {(positions[data.department] ?? []).map((position) => (
                            <option key={position}>{position}</option>
                        ))}
                    </select>
                </Field>
                <Field label="Registered full name">
                    <input
                        required
                        type="text"
                        autoComplete="name"
                        value={data.full_name}
                        onChange={(event) =>
                            setData('full_name', event.target.value)
                        }
                    />
                    {errors.full_name && <Error text={errors.full_name} />}
                </Field>
                <Field label="Resident account password">
                    <div className="relative">
                        <input
                            required
                            type={showPassword ? 'text' : 'password'}
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(event) =>
                                setData('password', event.target.value)
                            }
                        />
                        <button
                            type="button"
                            onClick={() =>
                                setShowPassword((current) => !current)
                            }
                            className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-gray-400 hover:text-gray-700"
                            aria-label={
                                showPassword ? 'Hide password' : 'Show password'
                            }
                        >
                            {showPassword ? <EyeOffIcon /> : <EyeIcon />}
                        </button>
                    </div>
                    {errors.password && <Error text={errors.password} />}
                </Field>
                <button
                    disabled={processing}
                    className="w-full rounded-lg bg-[#3f6f3d] px-4 py-2.5 font-semibold text-white transition hover:bg-[#315a30] disabled:opacity-60"
                >
                    {processing ? 'Signing in...' : 'Sign in'}
                </button>
            </form>
        </main>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block text-sm font-semibold text-gray-700">
            <span className="mb-1.5 block">{label}</span>
            <div className="[&_input]:w-full [&_input]:rounded-lg [&_input]:border [&_input]:border-gray-300 [&_input]:px-3 [&_input]:py-2.5 [&_select]:w-full [&_select]:rounded-lg [&_select]:border [&_select]:border-gray-300 [&_select]:px-3 [&_select]:py-2.5">
                {children}
            </div>
        </label>
    );
}

function Error({ text }: { text: string }) {
    return <p className="mt-1 text-xs text-red-600">{text}</p>;
}

function EyeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
    );
}

function EyeOffIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m3 3 18 18" />
            <path d="M10.6 10.7a2 2 0 0 0 2.7 2.7" />
            <path d="M9.9 4.3A11 11 0 0 1 12 4c6.5 0 10 8 10 8a15 15 0 0 1-2.1 3.3" />
            <path d="M6.5 6.6C3.6 8.4 2 12 2 12s3.5 8 10 8a9.8 9.8 0 0 0 4.2-.9" />
        </svg>
    );
}
