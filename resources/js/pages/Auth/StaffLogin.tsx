import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, EyeOff, Loader2, LogIn } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';

import AuthLayout from '@/layouts/AuthLayout';

type Barangay = { id: number; name: string };
type Props = { barangays: Barangay[]; errors?: Record<string, string> };

const positions: Record<string, string[]> = {
    lupon: ['Lupon Secretary', 'Lupon Staff / Member', 'Punong Barangay'],
    clerk: ['Barangay Clerk', 'Document Officer'],
    admin: ['Punong Barangay', 'Barangay Secretary', 'Barangay Treasurer'],
    pwd: ['PWD Affairs Officer'],
    senior: ['OSCA Head'],
};

const fieldClass =
    'h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 disabled:cursor-not-allowed disabled:bg-canvas disabled:text-ink-faint';

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
        <AuthLayout variant="staff">
            <Head title="Unified Staff Login" />

            <div className="rounded-xl border border-line-soft bg-surface p-6 shadow-card">
                <h1 className="text-lg font-semibold tracking-tight text-ink-strong">
                    Staff Login
                </h1>
                <p className="mt-1 text-sm text-ink-muted">
                    Sign in to access your barangay management system.
                </p>

                <form onSubmit={submit} className="mt-5 grid gap-4">
                    <Field label="Barangay">
                        <select
                            required
                            value={data.barangay_id}
                            onChange={(event) =>
                                setData('barangay_id', event.target.value)
                            }
                            className={fieldClass}
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
                            className={fieldClass}
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
                            className={fieldClass}
                        >
                            <option value="">Select position</option>
                            {(positions[data.department] ?? []).map(
                                (position) => (
                                    <option key={position}>{position}</option>
                                ),
                            )}
                        </select>
                    </Field>

                    <Field label="Full name">
                        <input
                            required
                            type="text"
                            autoComplete="name"
                            value={data.full_name}
                            onChange={(event) =>
                                setData('full_name', event.target.value)
                            }
                            placeholder="Registered full name"
                            className={fieldClass}
                        />
                        {errors.full_name && <Error text={errors.full_name} />}
                    </Field>

                    <Field label="Password">
                        <div className="relative">
                            <input
                                required
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(event) =>
                                    setData('password', event.target.value)
                                }
                                placeholder="••••••••"
                                className={`${fieldClass} pr-11`}
                            />
                            <button
                                type="button"
                                onClick={() =>
                                    setShowPassword((current) => !current)
                                }
                                className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-faint transition-colors hover:text-ink-muted"
                                aria-label={
                                    showPassword
                                        ? 'Hide password'
                                        : 'Show password'
                                }
                            >
                                {showPassword ? (
                                    <EyeOff className="size-4" />
                                ) : (
                                    <Eye className="size-4" />
                                )}
                            </button>
                        </div>
                        {errors.password && <Error text={errors.password} />}
                    </Field>

                    <button
                        type="submit"
                        disabled={processing}
                        className="mt-1 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white transition-colors hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {processing ? (
                            <>
                                <Loader2 className="size-4 animate-spin" />
                                Signing in…
                            </>
                        ) : (
                            <>
                                <LogIn className="size-4" />
                                Login
                            </>
                        )}
                    </button>
                </form>
            </div>

            <p className="mt-5 text-center text-sm text-ink-muted">
                Resident?{' '}
                <Link
                    href="/resident/login"
                    className="font-medium text-brand hover:underline"
                >
                    Sign in to the resident portal
                </Link>
            </p>
        </AuthLayout>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-ink">
                {label}
            </span>
            {children}
        </label>
    );
}

function Error({ text }: { text: string }) {
    return <p className="mt-1 text-xs text-red-600">{text}</p>;
}
