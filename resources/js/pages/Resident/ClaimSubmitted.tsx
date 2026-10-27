import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Claim = {
    reference: string;
    status: string;
};

type Props = {
    claim: Claim;
};

export default function ClaimSubmitted({ claim }: Props) {
    const [sending, setSending] = useState(false);

    const resend = () => {
        setSending(true);

        router.post(
            `/resident/claim/${claim.reference}/resend-verification`,
            {},
            { onFinish: () => setSending(false) },
        );
    };

    return (
        <div className="min-h-screen bg-[#f7f6f0] px-4 py-10">
            <Head title="Application Status" />

            <div className="mx-auto w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
                <div className="mt-5 text-center">
                    <h1 className="text-2xl font-bold text-gray-900">
                        Application Status
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-gray-600">
                        {claim.status.replaceAll('_', ' ')}
                    </p>
                </div>

                {claim.status === 'pending' && (
                    <button
                        type="button"
                        onClick={resend}
                        disabled={sending}
                        className="mt-5 w-full rounded-lg border border-gray-300 bg-white px-3 py-3 text-sm font-semibold text-gray-700 disabled:opacity-60"
                    >
                        {sending ? 'Sending...' : 'Resend Verification Link'}
                    </button>
                )}

                <Link
                    href="/resident/login"
                    className="mt-6 flex min-h-12 w-full items-center justify-center rounded-xl bg-[#3f6f3d] px-5 py-3 text-sm font-bold text-white hover:bg-[#315b30]"
                >
                    Back to Resident Login
                </Link>
            </div>
        </div>
    );
}
