import { Head, Link } from '@inertiajs/react';

type Props = {
    status: string;
    title: string;
    message: string;
};

export default function RegistrationEmailVerification({
    status,
    title,
    message,
}: Props) {
    const success = status === 'verified';

    return (
        <div className="flex min-h-screen items-center justify-center bg-[#f7f6f0] px-4 py-10">
            <Head title={title} />
            <main className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 text-center shadow-sm sm:p-8">
                <div
                    className={`mx-auto flex h-14 w-14 items-center justify-center rounded-full ${success ? 'bg-[#e8f1e4] text-[#3f6f3d]' : 'bg-amber-50 text-amber-700'}`}
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        className="h-7 w-7"
                        stroke="currentColor"
                        strokeWidth="2.5"
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
                <h1 className="mt-5 text-xl font-bold text-gray-900">
                    {title}
                </h1>
                <p className="mt-2 text-sm leading-6 text-gray-600">
                    {message}
                </p>
                <div className="mt-6 flex flex-col gap-2">
                    <Link
                        href="/resident/register/verification/resend"
                        className="rounded-lg bg-[#3f6f3d] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#315b30]"
                    >
                        Request another link
                    </Link>
                    <Link
                        href="/resident/register"
                        className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Create Resident Registration
                    </Link>
                </div>
            </main>
        </div>
    );
}
