import { Head, Link } from '@inertiajs/react';

type Registration = {
    reference: string;
    status: string;
    submitted_at: string | null;
};

type Props = {
    registration: Registration;
};

export default function RegistrationPending({
    registration,
}: Props) {
    return (
        <>
            <Head title="Registration Submitted" />

            <div className="min-h-screen bg-[#f7f6f0]">
                <header className="border-b border-[#315b30] bg-[#3f6f3d]">
                    <div className="mx-auto flex min-h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                        <Link
                            href="/resident/login"
                            className="inline-flex items-center gap-3 text-white"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white text-sm font-bold text-[#3f6f3d]">
                                KB
                            </div>

                            <div>
                                <p className="text-base font-bold leading-tight sm:text-lg">
                                    KaBarangay
                                </p>

                                <p className="text-xs text-white/80">
                                    Resident Portal
                                </p>
                            </div>
                        </Link>
                    </div>
                </header>

                <main className="mx-auto flex min-h-[calc(100vh-64px)] w-full max-w-7xl items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
                    <section className="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-8">
                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                className="h-8 w-8"
                                stroke="currentColor"
                                strokeWidth="2.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="m5 12 4 4L19 6" />
                            </svg>
                        </div>

                        <div className="mt-5 text-center">
                            <h1 className="text-2xl font-bold text-gray-900">
                                Registration Submitted
                            </h1>

                            <p className="mt-2 text-sm leading-6 text-gray-600">
                                Your application has been received
                                and is waiting for verification by
                                your barangay.
                            </p>
                        </div>

                        <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <div className="flex items-start gap-3">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    className="mt-0.5 h-5 w-5 shrink-0 text-amber-700"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                >
                                    <circle
                                        cx="12"
                                        cy="12"
                                        r="9"
                                    />
                                    <path d="M12 7v5l3 2" />
                                </svg>

                                <div>
                                    <p className="text-sm font-bold text-amber-900">
                                        Pending Admin Verification
                                    </p>

                                    <p className="mt-1 text-xs leading-5 text-amber-800">
                                        Your account is not active
                                        yet. Authorized barangay
                                        personnel must verify your
                                        information, valid ID and
                                        live identity photo.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                Application Reference
                            </p>

                            <p className="mt-2 break-all font-mono text-sm font-semibold text-gray-700">
                                {registration.reference}
                            </p>

                            <p className="mt-2 text-xs leading-5 text-gray-500">
                                Keep this reference for your
                                registration record.
                            </p>
                        </div>

                        <div className="mt-5 space-y-3">
                            <ProcessItem
                                number="1"
                                title="Registration submitted"
                                complete
                            />

                            <ProcessItem
                                number="2"
                                title="Barangay verification"
                            />

                            <ProcessItem
                                number="3"
                                title="Account approval"
                            />

                            <ProcessItem
                                number="4"
                                title="Resident ID and QR activation"
                            />
                        </div>

                        <Link
                            href="/resident/login"
                            className="mt-6 flex min-h-12 w-full items-center justify-center rounded-xl bg-[#3f6f3d] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#315b30]"
                        >
                            Back to Resident Login
                        </Link>
                    </section>
                </main>
            </div>
        </>
    );
}

function ProcessItem({
    number,
    title,
    complete = false,
}: {
    number: string;
    title: string;
    complete?: boolean;
}) {
    return (
        <div className="flex items-center gap-3">
            <div
                className={[
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                    complete
                        ? 'bg-[#3f6f3d] text-white'
                        : 'border border-gray-300 bg-white text-gray-400',
                ].join(' ')}
            >
                {complete ? (
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        className="h-4 w-4"
                        stroke="currentColor"
                        strokeWidth="3"
                    >
                        <path d="m5 12 4 4L19 6" />
                    </svg>
                ) : (
                    number
                )}
            </div>

            <p
                className={[
                    'text-sm',
                    complete
                        ? 'font-semibold text-gray-800'
                        : 'text-gray-500',
                ].join(' ')}
            >
                {title}
            </p>
        </div>
    );
}