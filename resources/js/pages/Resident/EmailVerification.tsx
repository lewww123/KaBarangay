import { Head, Link } from '@inertiajs/react';

type Props = {
    status: string;
    title: string;
    message: string;
};

export default function EmailVerification({ status, title, message }: Props) {
    const success = status === 'verified';

    return (
        <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
            <Head title={title} />

            <div className="w-full max-w-md rounded-xl border border-line-soft bg-white p-6 text-center shadow-card sm:p-8">
                <div
                    className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full ${success ? 'bg-brand-soft text-brand' : 'bg-amber-50 text-amber-700'}`}
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        className="h-8 w-8"
                        stroke="currentColor"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        {success ? (
                            <path d="m5 12 4 4L19 6" />
                        ) : (
                            <>
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 8v4M12 16h.01" />
                            </>
                        )}
                    </svg>
                </div>

                <h1 className="mt-5 text-xl font-semibold tracking-tight text-ink-strong">
                    {title}
                </h1>
                <p className="mt-2 text-sm leading-6 text-ink-muted">
                    {message}
                </p>

                <div className="mt-6 flex flex-col gap-2">
                    <Link
                        href="/resident/register"
                        className="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-deep"
                    >
                        Return to Registration
                    </Link>
                    <Link
                        href="/resident/login"
                        className="rounded-lg border border-line px-4 py-2.5 text-sm font-semibold text-ink hover:bg-canvas"
                    >
                        Back to Login
                    </Link>
                </div>
            </div>
        </div>
    );
}
