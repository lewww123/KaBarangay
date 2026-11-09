import { Head } from '@inertiajs/react';
import { Check, X } from 'lucide-react';

interface Verification {
    valid: boolean;
    message: string;
    barangay: string | null;
}

interface Props {
    verification: Verification;
}

export default function VerifyResident({ verification }: Props) {
    return (
        <>
            <Head title="Resident Verification" />

            <div className="min-h-screen bg-canvas px-4 py-8 sm:px-6">
                <div className="mx-auto flex min-h-[90vh] max-w-md items-center justify-center">
                    <div className="w-full overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-line-soft">
                        {/* Header */}
                        <div className="bg-brand px-6 py-7 text-center text-white">
                            <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-white text-brand">
                                {verification.valid ? (
                                    <Check className="size-8" strokeWidth={2.5} />
                                ) : (
                                    <X className="size-8" strokeWidth={2.5} />
                                )}
                            </div>

                            <h1 className="text-xl font-semibold tracking-tight text-white sm:text-2xl">
                                {verification.valid
                                    ? 'Resident Verified'
                                    : 'Verification Failed'}
                            </h1>

                            <p className="mt-2 text-sm text-white/70">
                                KaBarangay Resident Verification
                            </p>
                        </div>

                        {/* Content */}
                        <div className="px-6 py-7">
                            {/* Valid QR */}
                            {verification.valid ? (
                                <>
                                    <div className="mb-6 rounded-xl border border-green-200 bg-green-50 p-4 text-center">
                                        <p className="text-sm font-medium text-green-800">
                                            {verification.message}
                                        </p>

                                        <p className="mt-1 text-xs text-green-700">
                                            This QR code is associated with an
                                            active resident record.
                                        </p>
                                    </div>

                                    {verification.barangay && (
                                        <div className="border-t border-line-soft pt-4 text-center">
                                            <p className="text-xs font-medium tracking-wide text-ink-muted uppercase">
                                                Barangay
                                            </p>

                                            <p className="mt-1 font-semibold text-ink-strong">
                                                {verification.barangay}
                                            </p>
                                        </div>
                                    )}

                                    <p className="mt-6 text-center text-xs leading-5 text-ink-muted">
                                        For privacy, personal details are not
                                        displayed on this public verification
                                        page.
                                    </p>
                                </>
                            ) : (
                                /* Invalid QR */
                                <div className="text-center">
                                    <div className="rounded-xl border border-red-200 bg-red-50 p-5">
                                        <p className="text-sm font-semibold text-red-800">
                                            {verification.message}
                                        </p>

                                        <p className="mt-2 text-xs leading-5 text-red-700">
                                            The QR code may be invalid, revoked,
                                            inactive, or no longer associated
                                            with an active resident.
                                        </p>
                                    </div>

                                    <p className="mt-6 text-sm text-ink-muted">
                                        Please contact the Barangay Office if
                                        you believe this verification result is
                                        incorrect.
                                    </p>
                                </div>
                            )}
                        </div>

                        {/* Footer */}
                        <div className="border-t border-line-soft bg-canvas px-5 py-3 text-center">
                            <p className="text-xs text-ink-muted">
                                KaBarangay • Resident Verification System
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
