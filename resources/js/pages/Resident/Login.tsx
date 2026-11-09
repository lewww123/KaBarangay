import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Eye,
    EyeOff,
    Info,
    Loader2,
    LogIn,
} from 'lucide-react';

import { FormEvent, useEffect, useState } from 'react';

type Barangay = {
    id: number;
    name: string;
    code: string;
    city_municipality?: string | null;
    province?: string | null;
};

type PageProps = {
    flash?: {
        success?: string | null;
        warning?: string | null;
        error?: string | null;
        info?: string | null;
    };
};

export default function Login() {
    const page = usePage<PageProps>();

    const [barangays, setBarangays] = useState<Barangay[]>([]);
    const [barangayId, setBarangayId] = useState('');
    const [barangaysLoading, setBarangaysLoading] = useState(true);
    const [barangaysError, setBarangaysError] = useState('');

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

    useEffect(() => {
        const loadBarangays = async () => {
            setBarangaysLoading(true);
            setBarangaysError('');

            try {
                const response = await fetch('/resident/login/barangays', {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

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

    const flash = page.props.flash?.success;

    const selectedBarangay =
        barangays.find((barangay) => String(barangay.id) === barangayId) ??
        null;

    return (
        <>
            <Head title="Resident Login" />

            <div className="mb-6">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-brand">
                    Resident portal
                </p>
                <h1 className="mt-1.5 text-xl font-semibold tracking-tight text-ink-strong">
                    Resident Login
                </h1>
                <p className="mt-1 text-sm text-ink-muted">
                    Sign in to access your barangay services, requests,
                    announcements, QR code, and resident account.
                </p>
            </div>

            <div className="overflow-hidden rounded-xl border border-line-soft bg-surface p-5 shadow-card sm:p-6">
                {flash && (
                    <div className="mb-4 flex items-start gap-2.5 rounded-lg bg-success-soft px-4 py-3 text-sm text-success">
                        <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                        {flash}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="grid gap-4">
                    <div className="grid gap-2">
                        <label
                            htmlFor="barangayId"
                            className="text-sm font-medium text-ink"
                        >
                            Barangay
                        </label>
                        <select
                            id="barangayId"
                            value={barangayId}
                            disabled={barangaysLoading}
                            onChange={(event) => {
                                setBarangayId(event.target.value);
                                if (errors.barangay_id) {
                                    setErrors((current) => ({
                                        ...current,
                                        barangay_id: undefined,
                                    }));
                                }
                            }}
                            className="h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink-strong outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 disabled:cursor-wait disabled:text-ink-faint"
                        >
                            <option value="">
                                {barangaysLoading
                                    ? 'Loading barangays…'
                                    : 'Select your barangay'}
                            </option>
                            {barangays.map((barangay) => (
                                <option
                                    key={barangay.id}
                                    value={barangay.id}
                                >
                                    {barangay.name} ({barangay.code})
                                </option>
                            ))}
                        </select>
                        {errors.barangay_id && (
                            <p className="text-sm text-destructive">
                                {errors.barangay_id}
                            </p>
                        )}
                        {!barangaysLoading &&
                            !barangaysError &&
                            barangays.length === 0 && (
                                <p className="text-sm text-warning">
                                    No active barangays are available.
                                </p>
                            )}
                        {barangaysError && (
                            <p className="text-sm text-destructive">
                                {barangaysError}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <label
                            htmlFor="identifier"
                            className="text-sm font-medium text-ink"
                        >
                            Resident ID, Email, or Mobile Number
                        </label>
                        <input
                            id="identifier"
                            type="text"
                            autoComplete="username"
                            placeholder="e.g. BSJ-RES-000001"
                            value={identifier}
                            onChange={(event) => {
                                setIdentifier(event.target.value);
                                if (errors.identifier || errors.login) {
                                    setErrors((current) => ({
                                        ...current,
                                        identifier: undefined,
                                        login: undefined,
                                    }));
                                }
                            }}
                            className="h-11 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink-strong outline-none transition placeholder:text-ink-faint focus:border-brand focus:ring-2 focus:ring-brand/15"
                        />
                        {errors.identifier && (
                            <p className="text-sm text-destructive">
                                {errors.identifier}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <label
                            htmlFor="password"
                            className="text-sm font-medium text-ink"
                        >
                            Password
                        </label>
                        <div className="relative">
                            <input
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="current-password"
                                placeholder="Enter your password"
                                value={password}
                                onChange={(event) => {
                                    setPassword(event.target.value);
                                    if (errors.password || errors.login) {
                                        setErrors((current) => ({
                                            ...current,
                                            password: undefined,
                                            login: undefined,
                                        }));
                                    }
                                }}
                                className="h-11 w-full rounded-lg border border-line bg-surface px-3 pr-11 text-sm text-ink-strong outline-none transition placeholder:text-ink-faint focus:border-brand focus:ring-2 focus:ring-brand/15"
                            />
                            <button
                                type="button"
                                onClick={() =>
                                    setShowPassword((value) => !value)
                                }
                                aria-label={
                                    showPassword
                                        ? 'Hide password'
                                        : 'Show password'
                                }
                                className="absolute right-1.5 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-ink-faint transition hover:bg-secondary hover:text-ink-muted"
                            >
                                {showPassword ? (
                                    <EyeOff className="size-4" />
                                ) : (
                                    <Eye className="size-4" />
                                )}
                            </button>
                        </div>
                        {errors.password && (
                            <p className="text-sm text-destructive">
                                {errors.password}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <label
                            htmlFor="remember"
                            className="flex cursor-pointer items-center gap-2 text-sm text-ink-muted"
                        >
                            <input
                                id="remember"
                                type="checkbox"
                                checked={remember}
                                onChange={(event) =>
                                    setRemember(event.target.checked)
                                }
                                className="size-4 rounded border-line accent-brand"
                            />
                            Remember me
                        </label>
                        <Link
                            href="/resident/forgot-password"
                            className="text-sm font-medium text-brand hover:underline"
                        >
                            Forgot Password?
                        </Link>
                    </div>

                    {errors.login && (
                        <div
                            role="alert"
                            className="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive-soft px-4 py-3 text-sm font-medium text-destructive"
                        >
                            <Info className="mt-0.5 size-4 shrink-0" />
                            {errors.login}
                        </div>
                    )}

                    <button
                        type="submit"
                        disabled={isSubmitting}
                        className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white transition-colors hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60"
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

                <div className="my-5 flex items-center gap-3">
                    <span className="h-px flex-1 bg-line" />
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                        New resident account
                    </span>
                    <span className="h-px flex-1 bg-line" />
                </div>

                <p className="text-center text-sm text-ink-muted">
                    Don’t have a KaBarangay resident account?
                </p>
                <Link
                    href="/resident/register"
                    className="mt-3 flex h-11 w-full items-center justify-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold text-brand transition-colors hover:bg-brand-soft"
                >
                    Create Resident Account
                </Link>

                <div className="mt-5 flex items-start gap-3 rounded-xl border border-dashed border-line bg-surface/60 p-4">
                    <Info className="mt-0.5 size-4 shrink-0 text-brand" />
                    <div>
                        <p className="text-sm font-semibold text-ink-strong">
                            New account approval
                        </p>
                        <p className="mt-1 text-xs leading-5 text-ink-muted">
                            New resident accounts must be reviewed and approved
                            by the barangay before they can sign in.
                        </p>
                    </div>
                </div>

                <div className="mt-5 rounded-xl border border-line-soft bg-surface p-4">
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-ink-faint">
                        Demo — sign in as
                    </p>
                    <dl className="mt-2.5 grid gap-1.5 text-sm">
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-ink-muted">Resident ID</dt>
                            <dd className="font-mono tnum text-ink-strong">
                                {selectedBarangay
                                    ? selectedBarangay.code + '-ADM-001'
                                    : 'BSJ-ADM-001'}
                            </dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-ink-muted">Password</dt>
                            <dd className="font-mono text-ink-strong">
                                Admin@12345
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <p className="mt-5 text-center text-xs text-ink-faint">
                Need assistance? Please contact the{' '}
                {selectedBarangay?.name ?? 'Barangay San Jose'} office.
            </p>
        </>
    );
}
