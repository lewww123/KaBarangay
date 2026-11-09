import { Head, Link } from '@inertiajs/react';
import LegalLinks from '@/components/LegalLinks';
import { LogoMark } from '@/components/Logo';

export default function RegistrationPending() {
    return (
        <div className="min-h-screen bg-canvas">
            <Head title="Registration Submitted" />
            <header className="border-b border-brand-deep bg-brand">
                <div className="mx-auto flex min-h-16 max-w-7xl items-center px-4 sm:px-6">
                    <Link
                        href="/resident/login"
                        className="flex items-center gap-3 text-white"
                    >
                        <LogoMark
                            tone="onBrand"
                            size="md"
                            className="shrink-0"
                        />
                        <span>
                            <span className="block font-semibold">KaBarangay</span>
                            <span className="block text-xs text-white/80">
                                Resident Portal
                            </span>
                        </span>
                    </Link>
                </div>
            </header>
            <main className="mx-auto flex min-h-[calc(100dvh-64px)] max-w-7xl items-center justify-center px-4 py-8 sm:px-6">
                <section
                    aria-labelledby="registration-submitted-title"
                    className="w-full max-w-lg rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-8"
                >
                    <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-brand-soft text-brand">
                        <svg
                            aria-hidden="true"
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
                    <h1
                        id="registration-submitted-title"
                        className="mt-5 text-center text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl"
                    >
                        Account is waiting for admin approval
                    </h1>
                    <p className="mt-3 text-center text-sm leading-6 text-ink-muted">
                        Your account application was submitted successfully and
                        is waiting for admin approval. Authorized barangay
                        personnel will review your information and documents.
                    </p>
                    <div className="mt-6 rounded-xl border border-line-soft bg-surface p-4">
                        <h2 className="text-sm font-semibold text-brand-deep">
                            Check your email for updates
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-ink">
                            Ipapadala sa email address na inilagay mo ang
                            approval, correction request, o desisyon ng admin.
                            Tingnan din ang iyong spam folder.
                        </p>
                    </div>
                    <p className="mt-4 text-center text-sm leading-6 text-ink-muted">
                        You can sign in once your account has been approved.
                        Para sa mga tanong, makipag-ugnayan sa administrator ng
                        napili mong barangay.
                    </p>
                    <Link
                        href="/resident/login"
                        className="mt-6 flex min-h-12 items-center justify-center rounded-xl bg-brand px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-deep"
                    >
                        Back to Resident Login
                    </Link>
                    <LegalLinks />
                </section>
            </main>
        </div>
    );
}
