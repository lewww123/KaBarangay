import type { ReactNode } from 'react';

import logoUrl from '@/assets/kabarangay-logo.png';
import DesktopOnly from '@/components/DesktopOnly';
import { Logo } from '@/components/Logo';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    /**
     * "staff" = split-screen brand panel + form, desktop-only (admin / staff),
     * "resident" = the same split-screen on desktop, collapsing to a compact
     * logo + card column on phones.
     */
    variant: 'staff' | 'resident';
    maxWidth?: string;
    className?: string;
};

function FormBackdrop() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 overflow-hidden"
        >
            <div className="absolute inset-x-0 top-0 h-[30rem] bg-[linear-gradient(to_bottom,var(--color-brand-muted),var(--color-canvas)_70%)]" />
            <div className="absolute inset-0 bg-[radial-gradient(circle,var(--color-line)_1px,transparent_1px)] [background-size:22px_22px] opacity-60" />
            <div className="absolute -top-52 -right-44 size-[520px] rounded-full border border-brand/25" />
            <div className="absolute -bottom-64 -left-56 size-[620px] rounded-full border border-brand/25" />
        </div>
    );
}

function BrandPanel({
    title,
    eyebrow,
    description,
    bullets,
}: {
    title: string;
    eyebrow: string;
    description: string;
    bullets: string[];
}) {
    return (
        <div className="relative hidden overflow-hidden bg-[linear-gradient(160deg,var(--color-brand),var(--color-brand-deep))] lg:flex lg:flex-col lg:justify-between lg:p-10">
            <div aria-hidden className="pointer-events-none absolute inset-0">
                <div className="absolute -top-24 -right-24 size-72 rounded-full bg-white/10" />
                <div className="absolute -bottom-32 -left-20 size-80 rounded-full bg-white/5" />
                <div className="absolute right-[-10rem] bottom-[-10rem] size-[28rem] rounded-full border border-white/15" />
            </div>

            <div className="relative">
                <img
                    src={logoUrl}
                    alt="KaBarangay"
                    className="h-24 w-auto brightness-0 invert"
                />
                <p className="mt-4 text-sm text-white/75">{eyebrow}</p>
            </div>

            <div className="relative max-w-md">
                <h1 className="text-3xl leading-tight font-semibold tracking-tight text-white">
                    {title}
                </h1>
                <p className="mt-4 text-sm leading-relaxed text-white/75">
                    {description}
                </p>
                <ul className="mt-8 space-y-2.5 text-sm text-white/80">
                    {bullets.map((bullet) => (
                        <li key={bullet} className="flex items-center gap-2.5">
                            <span className="size-1.5 rounded-full bg-white/70" />
                            {bullet}
                        </li>
                    ))}
                </ul>
            </div>

            <p className="relative text-xs text-white/55">
                © {new Date().getFullYear()} KaBarangay · Barangay Management
                System
            </p>
        </div>
    );
}

export default function AuthLayout({
    children,
    variant,
    maxWidth = 'max-w-md',
    className,
}: Props) {
    if (variant === 'resident') {
        return (
            <div className="min-h-screen bg-canvas lg:grid lg:h-screen lg:grid-cols-[1.05fr_1fr]">
                <BrandPanel
                    title="Barangay services, closer to home."
                    eyebrow="Resident Portal"
                    description="I-track ang certificate requests, makita ang announcements at programs, at makipag-ugnayan sa barangay — lahat mula sa iyong phone."
                    bullets={[
                        'Certificate requests and tracking',
                        'Notices, updates at programs',
                        'Sector services at lupon updates',
                    ]}
                />

                <div className="relative bg-canvas lg:h-screen">
                    <FormBackdrop />

                    <div className="relative flex min-h-screen flex-col px-5 py-8 lg:h-screen lg:min-h-0 lg:items-center lg:justify-center lg:px-8 short:py-4 shorter:py-2">
                        <div className={cn('w-full', maxWidth, className)}>
                            <Logo className="mb-8 lg:hidden" />
                            {children}
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <>
            <div className="hidden min-h-screen bg-canvas lg:grid lg:grid-cols-[1.05fr_1fr]">
                <BrandPanel
                    title="Barangay services, in one tidy place."
                    eyebrow="Barangay Management System"
                    description="KaBarangay keeps resident records, certificate requests, announcements and blotter cases organised for barangay staff — from the clerk's desk to the admin dashboard."
                    bullets={[
                        'Certificate request tracking',
                        'Sector desks for clerk, senior, PWD and lupon',
                        'Audit log for every action',
                    ]}
                />

                <div className="relative bg-canvas">
                    <FormBackdrop />

                    <div className="relative flex min-h-screen flex-col items-center justify-center px-5 py-10">
                        <div className={cn('w-full', maxWidth, className)}>
                            {children}

                            <p className="mt-6 text-center text-xs text-ink-faint">
                                Authorized personnel only
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="lg:hidden">
                <DesktopOnly />
            </div>
        </>
    );
}
