import {
    readConnectivity,
    subscribeConnectivity,
} from '@/lib/offlineConnectivity';
import { router } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore } from 'react';

const OFFLINE_MESSAGE =
    'You are currently offline, but you can still access KaBarangay and submit supported requests. Your requests will be sent automatically once your internet connection returns.';

const DEGRADED_MESSAGE =
    'KaBarangay is reachable, but the barangay data service cannot be read right now. Saved pages stay available, nothing you submitted was lost, and no action is sent until the data service returns.';

export default function OfflineConnectivityIndicator({
    onRetry,
}: {
    onRetry: () => void;
}) {
    const state = useSyncExternalStore(
        subscribeConnectivity,
        readConnectivity,
        readConnectivity,
    );
    const [offset, setOffset] = useState(64);

    useEffect(() => {
        let header: HTMLElement | null = null;
        let observer: ResizeObserver | undefined;
        const measure = () =>
            setOffset(
                header
                    ? Math.round(header.getBoundingClientRect().bottom)
                    : 64,
            );
        const attach = () => {
            const next = document.querySelector('header');
            if (next === header) return;
            observer?.disconnect();
            header = next as HTMLElement | null;
            if (header && typeof ResizeObserver !== 'undefined') {
                observer = new ResizeObserver(measure);
                observer.observe(header);
            }
            measure();
        };
        attach();
        const stopFinish = router.on('finish', attach);
        window.addEventListener('resize', measure);
        return () => {
            stopFinish();
            observer?.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, []);

    if (
        !state.notice &&
        state.online &&
        !state.degraded &&
        !state.syncing &&
        state.pending === 0 &&
        !state.stale
    )
        return null;

    return (
        <div
            data-kb-offline-control
            role="status"
            aria-live="polite"
            className="pointer-events-none fixed right-3 z-[70] flex max-w-[min(24rem,calc(100vw-1.5rem))] flex-col items-end gap-1"
            style={{ top: offset + 8 }}
        >
            {state.notice && (
                <p
                    className={`pointer-events-auto rounded-lg border px-3 py-1.5 text-xs font-semibold shadow-md sm:text-sm ${
                        state.notice.tone === 'success'
                            ? 'border-brand-muted bg-brand-soft text-brand-deep'
                            : 'border-rose-300 bg-rose-50 text-rose-900'
                    }`}
                >
                    {state.notice.message}
                </p>
            )}
            {state.degraded && (
                <div className="pointer-events-auto w-full rounded-xl border border-sky-300 bg-sky-50/95 px-3 py-2 shadow-md">
                    <p className="flex items-center gap-2 text-xs font-semibold text-sky-950 sm:text-sm">
                        <span
                            aria-hidden
                            className="h-2 w-2 shrink-0 rounded-full bg-sky-600"
                        />
                        Barangay data unavailable
                        {state.pending > 0 && (
                            <span className="font-normal">
                                · {state.pending} to send
                            </span>
                        )}
                    </p>
                    <p className="mt-1 hidden text-xs leading-5 text-sky-900 sm:block">
                        {DEGRADED_MESSAGE}
                    </p>
                    {state.stale && (
                        <p className="mt-1 text-xs leading-5 text-sky-900">
                            Showing information saved on this device. It may
                            not be current.
                        </p>
                    )}
                    {state.pending > 0 && (
                        <button
                            type="button"
                            onClick={onRetry}
                            className="mt-1.5 rounded-lg border border-sky-400 px-2.5 py-1 text-xs font-semibold text-sky-950 transition hover:bg-sky-100"
                        >
                            Try to send now
                        </button>
                    )}
                </div>
            )}
            {state.online || state.degraded ? (
                <>
                    {(state.syncing || state.pending > 0) && (
                        <p className="rounded-lg border border-stone-200 bg-white/95 px-3 py-1.5 text-xs font-semibold text-stone-700 shadow-md sm:text-sm">
                            {state.syncing
                                ? 'Syncing…'
                                : `${state.pending} request${state.pending === 1 ? '' : 's'} ${
                                      state.degraded
                                          ? 'waiting for the barangay data service'
                                          : 'waiting for internet'
                                  }`}
                        </p>
                    )}
                    {state.stale && (
                        <p className="pointer-events-auto rounded-lg border border-stone-200 bg-white/95 px-3 py-1.5 text-xs text-stone-600 shadow-md">
                            This page is still showing saved information. It
                            refreshes once the connection is confirmed.
                        </p>
                    )}
                </>
            ) : (
                <div className="pointer-events-auto w-full rounded-xl border border-amber-300 bg-amber-50/95 px-3 py-2 shadow-md">
                    <p className="flex items-center gap-2 text-xs font-semibold text-amber-950 sm:text-sm">
                        <span
                            aria-hidden
                            className="h-2 w-2 shrink-0 rounded-full bg-amber-500"
                        />
                        Offline Mode
                        {state.pending > 0 && (
                            <span className="font-normal">
                                · {state.pending} to send
                            </span>
                        )}
                    </p>
                    <p className="mt-1 hidden text-xs leading-5 text-amber-900 sm:block">
                        {OFFLINE_MESSAGE}
                    </p>
                    {state.stale && (
                        <p className="mt-1 text-xs leading-5 text-amber-900">
                            Showing information saved on this device. It may not
                            be current.
                        </p>
                    )}
                    {state.pending > 0 && (
                        <button
                            type="button"
                            onClick={onRetry}
                            className="mt-1.5 rounded-lg border border-amber-400 px-2.5 py-1 text-xs font-semibold text-amber-950 transition hover:bg-amber-100"
                        >
                            Try to sync now
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
