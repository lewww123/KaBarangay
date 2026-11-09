import { Head, Link, router } from '@inertiajs/react';
import { Loader2, LogIn } from 'lucide-react';
import LegalLinks from '@/components/LegalLinks';
import AuthLayout from '@/layouts/AuthLayout';

import { FormEvent, useEffect, useState } from 'react';

type Barangay = {
    id: number;

    name: string;

    code: string;

    city_municipality?: string | null;

    province?: string | null;
};

export default function Login() {
    /*

    |--------------------------------------------------------------------------

    | Barangay

    |--------------------------------------------------------------------------

    */

    const [barangays, setBarangays] = useState<Barangay[]>([]);

    const [barangayId, setBarangayId] = useState('');

    const [barangaysLoading, setBarangaysLoading] = useState(true);

    const [barangaysError, setBarangaysError] = useState('');

    /*

    |--------------------------------------------------------------------------

    | Login Fields

    |--------------------------------------------------------------------------

    */

    const [identifier, setIdentifier] = useState('');

    const [password, setPassword] = useState('');

    const [remember, setRemember] = useState(false);

    const [showPassword, setShowPassword] = useState(false);

    const [isSubmitting, setIsSubmitting] = useState(false);

    const [errors, setErrors] = useState<{
        barangay_id?: string;
        identifier?: string;
        password?: string;
        login?: string;
    }>({});

    /*

    |--------------------------------------------------------------------------

    | Load Barangays

    |--------------------------------------------------------------------------

    */

    useEffect(() => {
        const loadBarangays = async () => {
            setBarangaysLoading(true);

            setBarangaysError('');

            try {
                const response = await fetch(
                    '/resident/login/barangays',

                    {
                        method: 'GET',

                        headers: {
                            Accept: 'application/json',

                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error('Unable to load barangays.');
                }

                const result = (await response.json()) as {
                    barangays?: Barangay[];
                };

                setBarangays(
                    Array.isArray(result.barangays) ? result.barangays : [],
                );
            } catch {
                setBarangays([]);

                setBarangaysError(
                    'We could not load the barangay list. Please refresh the page and try again.',
                );
            } finally {
                setBarangaysLoading(false);
            }
        };

        void loadBarangays();
    }, []);

    /*

    |--------------------------------------------------------------------------

    | Submit

    |--------------------------------------------------------------------------

    */

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isSubmitting) {
            return;
        }

        const nextErrors: {
            barangay_id?: string;
            identifier?: string;
            password?: string;
        } = {};

        if (!barangayId) {
            nextErrors.barangay_id = 'Please select your barangay.';
        }

        if (!identifier.trim()) {
            nextErrors.identifier =
                'Enter your Resident ID, email, or mobile number.';
        }

        if (!password.trim()) {
            nextErrors.password = 'Enter your password.';
        }

        setErrors(nextErrors);

        if (Object.keys(nextErrors).length > 0) {
            return;
        }

        setIsSubmitting(true);
        setErrors({});

        router.post(
            '/resident/login',
            {
                barangay_id: Number(barangayId),
                identifier: identifier.trim(),
                password,
                remember,
            },
            {
                preserveScroll: true,

                onError: (serverErrors) => {
                    setErrors({
                        barangay_id: serverErrors.barangay_id,
                        identifier: serverErrors.identifier,
                        password: serverErrors.password,
                        login: serverErrors.login,
                    });
                },

                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Resident Login" />

            <AuthLayout variant="resident">
                <div className="w-full">
                        {/* =================================================

                            LOGIN CARD

                        ================================================= */}

                        <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-7">
                            {/* HEADING */}

                            <div className="text-center">
                                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-brand-soft text-brand">
                                    <ResidentIcon />
                                </div>

                                <h1 className="mt-4 text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                                    Resident Login
                                </h1>

                                <p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-ink-muted">
                                    Sign in to access your barangay services,
                                    requests, announcements, QR code, and
                                    resident account.
                                </p>
                            </div>

                            {/* FORM */}

                            <form
                                onSubmit={handleSubmit}

                                className="mt-6 space-y-4"
                            >
                                {/* BARANGAY */}

                                <Field
                                    label="Barangay"

                                    error={errors.barangay_id}
                                >
                                    <div
                                        className={`flex min-h-11 items-center gap-3 rounded-xl border bg-white px-3.5 transition focus-within:ring-2 ${
                                            errors.barangay_id
                                                ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-100'
                                                : 'border-line focus-within:border-brand focus-within:ring-brand/15'
                                        }`}
                                    >
                                        <div className="shrink-0 text-ink-faint">
                                            <BarangayIcon />
                                        </div>

                                        <select
                                            value={barangayId}

                                            disabled={barangaysLoading}

                                            onChange={(event) => {
                                                setBarangayId(
                                                    event.target.value,
                                                );

                                                if (errors.barangay_id) {
                                                    setErrors((current) => ({
                                                        ...current,

                                                        barangay_id: undefined,
                                                    }));
                                                }
                                            }}

                                            className="h-11 min-w-0 flex-1 border-0 bg-transparent p-0 text-base text-ink-strong ring-0 outline-none focus:ring-0 focus:outline-none disabled:cursor-wait disabled:text-ink-faint sm:text-sm"
                                        >
                                            <option value="">
                                                {barangaysLoading
                                                    ? 'Loading barangays...'
                                                    : 'Select your barangay'}
                                            </option>

                                            {barangays.map((barangay) => (
                                                <option
                                                    key={barangay.id}

                                                    value={barangay.id}
                                                >
                                                    {barangay.name}

                                                    {barangay.code
                                                        ? ` (${barangay.code})`
                                                        : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    {barangaysError && (
                                        <p className="mt-1.5 text-xs font-medium text-red-600">
                                            {barangaysError}
                                        </p>
                                    )}

                                    {!barangaysLoading &&
                                        !barangaysError &&
                                        barangays.length === 0 && (
                                            <p className="mt-1.5 text-xs font-medium text-amber-700">
                                                No active barangays are
                                                available.
                                            </p>
                                        )}
                                </Field>

                                {/* IDENTIFIER */}

                                <Field
                                    label="Resident ID, Email, or Mobile Number"

                                    error={errors.identifier}
                                >
                                    <div className="flex min-h-11 items-center gap-3 rounded-xl border border-line bg-white px-3.5 transition focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/15">
                                        <div className="shrink-0 text-ink-faint">
                                            <UserIcon />
                                        </div>

                                        <input
                                            type="text"

                                            value={identifier}

                                            onChange={(event) => {
                                                setIdentifier(
                                                    event.target.value,
                                                );

                                                if (
                                                    errors.identifier ||
                                                    errors.login
                                                ) {
                                                    setErrors((current) => ({
                                                        ...current,
                                                        identifier: undefined,
                                                        login: undefined,
                                                    }));
                                                }
                                            }}

                                            autoComplete="username"

                                            placeholder="e.g. BSJ-RES-000001"

                                            className="h-11 min-w-0 flex-1 border-0 bg-transparent p-0 text-base text-ink-strong ring-0 outline-none placeholder:text-ink-faint focus:ring-0 focus:outline-none sm:text-sm"
                                        />
                                    </div>
                                </Field>

                                {/* PASSWORD */}

                                <Field
                                    label="Password"

                                    error={errors.password}
                                >
                                    <div className="flex min-h-11 items-center gap-3 rounded-xl border border-line bg-white px-3.5 transition focus-within:border-brand focus-within:ring-2 focus-within:ring-brand/15">
                                        <div className="shrink-0 text-ink-faint">
                                            <LockIcon />
                                        </div>

                                        <input
                                            type={
                                                showPassword
                                                    ? 'text'
                                                    : 'password'
                                            }

                                            value={password}

                                            onChange={(event) => {
                                                setPassword(event.target.value);

                                                if (
                                                    errors.password ||
                                                    errors.login
                                                ) {
                                                    setErrors((current) => ({
                                                        ...current,
                                                        password: undefined,
                                                        login: undefined,
                                                    }));
                                                }
                                            }}

                                            autoComplete="current-password"

                                            placeholder="Enter your password"

                                            className="h-11 min-w-0 flex-1 border-0 bg-transparent p-0 text-base text-ink-strong ring-0 outline-none placeholder:text-ink-faint focus:ring-0 focus:outline-none sm:text-sm"
                                        />

                                        <button
                                            type="button"

                                            onClick={() =>
                                                setShowPassword(
                                                    (current) => !current,
                                                )
                                            }

                                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-faint transition hover:bg-secondary hover:text-ink"

                                            aria-label={
                                                showPassword
                                                    ? 'Hide password'
                                                    : 'Show password'
                                            }
                                        >
                                            {showPassword ? (
                                                <EyeOffIcon />
                                            ) : (
                                                <EyeIcon />
                                            )}
                                        </button>
                                    </div>
                                </Field>

                                {/* REMEMBER + FORGOT */}

                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <label className="inline-flex cursor-pointer items-center gap-2">
                                        <input
                                            type="checkbox"

                                            checked={remember}

                                            onChange={(event) =>
                                                setRemember(
                                                    event.target.checked,
                                                )
                                            }

                                            className="h-4 w-4 rounded border-line accent-brand"
                                        />

                                        <span className="text-sm text-ink-muted">
                                            Remember me
                                        </span>
                                    </label>

                                    <Link
                                        href="/resident/forgot-password"

                                        className="text-sm font-semibold text-brand transition hover:text-brand-deep hover:underline"
                                    >
                                        Forgot Password?
                                    </Link>
                                </div>

                                {/* LOGIN ERROR */}

                                {errors.login && (
                                    <div className="rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm font-medium text-red-700">
                                        {errors.login}
                                    </div>
                                )}

                                {/* LOGIN */}

                                <button
                                    type="submit"
                                    disabled={isSubmitting}
                                    className="mt-1 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white transition-colors hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {isSubmitting ? (
                                        <>
                                            <Loader2 className="size-4 animate-spin" />
                                            Signing in…
                                        </>
                                    ) : (
                                        <>
                                            <LogIn className="size-4" />
                                            Sign In
                                        </>
                                    )}
                                </button>
                            </form>

                            <LegalLinks />
                            {/* DIVIDER */}

                            <div className="my-6 flex items-center gap-3">
                                <div className="h-px flex-1 bg-line-soft" />

                                <span className="text-xs font-medium text-ink-faint">
                                    NEW RESIDENT ACCOUNT
                                </span>

                                <div className="h-px flex-1 bg-line-soft" />
                            </div>

                            {/* CREATE ACCOUNT */}

                            <div className="text-center">
                                <p className="text-sm leading-6 text-ink-muted">
                                    Don't have a KaBarangay resident account?
                                </p>

                                <Link
                                    href="/resident/register"

                                    className="mt-3 flex min-h-11 w-full items-center justify-center rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-brand transition hover:bg-brand-soft"
                                >
                                    Create Resident Account
                                </Link>
                            </div>
                        </div>

                        {/* =================================================

                            APPROVAL NOTICE

                        ================================================= */}

                        <div className="mt-4 rounded-xl border border-line-soft bg-surface p-4">
                            <div className="flex items-start gap-3">
                                <div className="mt-0.5 shrink-0 text-brand">
                                    <InfoIcon />
                                </div>

                                <div>
                                    <p className="text-sm font-semibold text-ink-strong">
                                        New account approval
                                    </p>

                                    <p className="mt-1 text-xs leading-5 text-ink-muted">
                                        New resident accounts must be reviewed
                                        and approved by the barangay before they
                                        can sign in.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* =================================================

                            FOOTER

                        ================================================= */}

                        <div className="mt-5 text-center">
                            <p className="text-xs leading-5 text-ink-faint">
                                Need assistance? Please contact the Barangay San
                                Jose office.
                            </p>
                        </div>
                    </div>
        </AuthLayout>
        </>
    );
}

/*

|--------------------------------------------------------------------------

| Field

|--------------------------------------------------------------------------

*/

function Field({
    label,

    error,

    children,
}: {
    label: string;

    error?: string;

    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-semibold text-ink">
                {label}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-xs font-medium text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

/*

|--------------------------------------------------------------------------

| Icons

|--------------------------------------------------------------------------

*/

function ResidentIcon() {
    return (
        <svg
            viewBox="0 0 24 24"

            fill="none"

            className="h-6 w-6"

            stroke="currentColor"

            strokeWidth="2"

            strokeLinecap="round"

            strokeLinejoin="round"

            aria-hidden="true"
        >
            <circle
                cx="12"

                cy="8"

                r="4"
            />

            <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
        </svg>
    );
}

function BarangayIcon() {
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
            <path d="M3 21h18" />

            <path d="M5 21V9l7-5 7 5v12" />

            <path d="M9 21v-6h6v6" />

            <path d="M9 11h.01" />

            <path d="M15 11h.01" />
        </svg>
    );
}

function UserIcon() {
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
            <circle
                cx="12"

                cy="8"

                r="4"
            />

            <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
        </svg>
    );
}

function LockIcon() {
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
            <rect
                x="5"

                y="10"

                width="14"

                height="10"

                rx="2"
            />

            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
        </svg>
    );
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

            <circle
                cx="12"

                cy="12"

                r="3"
            />
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

function InfoIcon() {
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
            <circle
                cx="12"

                cy="12"

                r="9"
            />

            <path d="M12 11v6" />

            <path d="M12 7h.01" />
        </svg>
    );
}
