import { Monitor } from 'lucide-react';

import { Logo } from '@/components/Logo';

/**
 * Staff and admin desks are desktop-only. Below the `lg` breakpoint the
 * resident portal is the only surface that stays usable, so staff pages show
 * this message instead of a cramped table.
 */
export default function DesktopOnly() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-canvas px-6 py-12">
            <div className="w-full max-w-md rounded-2xl border border-line-soft bg-surface p-8 text-center shadow-card">
                <Logo className="justify-center" />

                <span className="mx-auto mt-8 grid size-12 place-items-center rounded-xl bg-brand-soft text-brand">
                    <Monitor className="size-6" />
                </span>

                <h1 className="mt-4 text-lg font-semibold tracking-tight text-ink-strong">
                    Best viewed on a desktop
                </h1>

                <p className="mt-2 text-sm leading-relaxed text-ink-muted">
                    The staff and admin desks need a wider screen. Open this page
                    on a laptop or desktop computer to continue.
                </p>

                <a
                    href="/resident/login"
                    className="mt-6 inline-flex h-10 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white transition-colors hover:bg-brand-hover"
                >
                    Go to the resident portal
                </a>
            </div>
        </main>
    );
}
