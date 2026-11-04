import { router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { useEffect, useState } from 'react';
import { offlineSnapshot } from '@/lib/offlineSnapshot';
import {
    offlineCertificateStore,
    prepareOfflineCertificates,
} from '@/lib/offlineCertificates';

type OfflineState = {
    online: boolean;
    ready: boolean;
    savedAt?: number;
    message?: string;
};
type WorkerReply = { ok: boolean; savedAt?: number };
const eligible = (page: Page) =>
    typeof page.props.offlineScope === 'string' &&
    /^(Resident|Admin|Clerk|Lupon|Pwd|Senior)\//i.test(page.component) &&
    !/\/(Login|Register|Registration|ForgotPassword|ResetPassword|Verify)/i.test(
        page.component,
    );

export default function OfflineSupport({ initialPage }: { initialPage: Page }) {
    const [state, setState] = useState<OfflineState>({
        online: true,
        ready: false,
    });
    const [privatePage, setPrivatePage] = useState(eligible(initialPage));
    const [pendingCertificates, setPendingCertificates] = useState(0);
    const [queueMessage, setQueueMessage] = useState('');
    const [syncing, setSyncing] = useState(false);
    const [isResident, setIsResident] = useState(
        initialPage.component.startsWith('Resident/'),
    );
    const syncNow = async () => {
        setSyncing(true);
        try {
            const store = await offlineCertificateStore();
            const result = await store.sync();
            setPendingCertificates(
                (await store.list()).filter((item) =>
                    ['pending', 'syncing', 'login-required'].includes(
                        item.status,
                    ),
                ).length,
            );
            setQueueMessage(
                result.loginRequired
                    ? 'Sign in to sync your own requests.'
                    : result.offline || result.retryLater
                      ? 'Requests remain pending sync.'
                      : result.busy
                        ? 'Sync is running in another tab.'
                        : 'Sync checked. Open local requests for results.',
            );
        } catch (error) {
            setQueueMessage(
                error instanceof Error
                    ? error.message
                    : 'Offline requests are not ready.',
            );
        } finally {
            setSyncing(false);
        }
    };

    useEffect(() => {
        let page = initialPage;
        let worker: ServiceWorker | null = null;
        let closed = false;
        let loggingOut = false;
        let networkAvailable = navigator.onLine;
        let preparedScope: unknown = null;
        let preparing = false;
        const prepareQueue = async () => {
            if (
                !worker ||
                preparing ||
                !navigator.onLine ||
                !networkAvailable ||
                !eligible(page) ||
                !page.component.startsWith('Resident/')
            )
                return;
            if (preparedScope === page.props.offlineScope) return;
            preparing = true;
            try {
                await prepareOfflineCertificates();
                preparedScope = page.props.offlineScope;
                if (!closed) {
                    setQueueMessage('Offline certificate drafts are ready.');
                    void syncNow();
                }
            } catch {
                if (!closed)
                    setQueueMessage(
                        'Open Certificate Requests online to prepare offline drafts.',
                    );
            } finally {
                preparing = false;
            }
        };
        const changedPage = (next: Page) => {
            page = next;
            setPrivatePage(eligible(page));
            setIsResident(page.component.startsWith('Resident/'));
            setPendingCertificates(0);
            setQueueMessage('');
            schedule();
            void prepareQueue();
        };
        let timer: ReturnType<typeof setTimeout>;
        let saving = false;
        const tell = (message: object): Promise<WorkerReply> =>
            new Promise((resolve, reject) => {
                if (!worker) {
                    reject(new Error('No service worker'));
                    return;
                }
                const channel = new MessageChannel();
                const timeout = setTimeout(
                    () => reject(new Error('Offline storage unavailable')),
                    15000,
                );
                channel.port1.onmessage = (event) => {
                    clearTimeout(timeout);
                    resolve(event.data);
                };
                worker.postMessage(message, [channel.port2]);
            });
        const save = async () => {
            if (
                closed ||
                loggingOut ||
                saving ||
                !worker ||
                !navigator.onLine ||
                !networkAvailable ||
                !eligible(page)
            )
                return;
            if (
                Object.values(page.deferredProps ?? {})
                    .flat()
                    .some((key) => page.props[key] === undefined)
            )
                return;
            const scope = page.props.offlineScope;
            saving = true;
            try {
                const assets = performance
                    .getEntriesByType('resource')
                    .map((item) => item.name);
                const reply = await tell({
                    type: 'KB_SAVE',
                    scope,
                    path: location.pathname + location.search,
                    title: document.title,
                    html: offlineSnapshot(Date.now()),
                    assets,
                });
                if (!closed && page.props.offlineScope === scope)
                    setState({
                        online: navigator.onLine && networkAvailable,
                        ready: reply.ok,
                        savedAt: reply.savedAt,
                        message: reply.ok
                            ? undefined
                            : 'Hindi pa handa ang offline copy ng page na ito.',
                    });
            } catch {
                if (!closed)
                    setState({
                        online: navigator.onLine && networkAvailable,
                        ready: false,
                        message:
                            'Hindi available ang offline saving sa browser na ito.',
                    });
            } finally {
                saving = false;
            }
        };
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(() => void save(), 900);
        };
        const observe = new MutationObserver((records) => {
            const relevant = records.some((record) => {
                const element =
                    record.target instanceof Element
                        ? record.target
                        : record.target.parentElement;
                return !element?.closest(
                    '[data-kb-offline-control], #kabarangay-ai-chat',
                );
            });
            if (relevant) schedule();
        });
        observe.observe(document.getElementById('app') ?? document.body, {
            subtree: true,
            childList: true,
        });
        const stopSuccess = router.on('success', (event) =>
            changedPage(event.detail.page),
        );
        const stopNavigate = router.on('navigate', (event) =>
            changedPage(event.detail.page),
        );
        const stopBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            const logout = ['/logout', '/resident/logout'].includes(
                visit.url.pathname,
            );
            if (logout) {
                loggingOut = true;
                clearTimeout(timer);
                if (!navigator.onLine || !networkAvailable) {
                    void tell({
                        type: 'KB_LOGOUT',
                        path: visit.url.pathname,
                    }).finally(() =>
                        location.replace('/offline.html?loggedout=1'),
                    );
                    return false;
                }
                // The worker also clears storage before forwarding online logout.
                void tell({
                    type: 'KB_LOGOUT',
                    path: visit.url.pathname,
                }).catch(() => {});
                return;
            }
            if (navigator.onLine && networkAvailable) return;
            if (visit.method.toLowerCase() === 'get') {
                if (!visit.async) location.assign(visit.url.href);
            } else
                setState((current) => ({
                    ...current,
                    online: false,
                    message:
                        'Offline — kailangan ng internet para i-save ang aksiyong ito.',
                }));
            return false;
        });
        const connection = () => {
            networkAvailable = navigator.onLine;
            setState((current) => ({ ...current, online: navigator.onLine }));
            if (navigator.onLine) {
                schedule();
                void prepareQueue().then(() => {
                    if (page.component.startsWith('Resident/')) void syncNow();
                });
            }
        };
        const queueChanged = () => {
            if (!page.component.startsWith('Resident/')) return;
            void offlineCertificateStore()
                .then((store) => store.list())
                .then((items) => {
                    if (!closed)
                        setPendingCertificates(
                            items.filter((item) =>
                                [
                                    'pending',
                                    'syncing',
                                    'login-required',
                                ].includes(item.status),
                            ).length,
                        );
                })
                .catch(() => {
                    if (!closed) setPendingCertificates(0);
                });
        };
        const workerMessage = (event: MessageEvent) => {
            if (event.data?.type === 'KB_NETWORK_OFFLINE') {
                networkAvailable = false;
                setState((current) => ({ ...current, online: false }));
            }
            if (event.data?.type === 'KB_CERT_QUEUE_CHANGED') queueChanged();
        };
        const blockSubmit = (event: Event) => {
            if (!navigator.onLine || !networkAvailable) {
                event.preventDefault();
                event.stopImmediatePropagation();
                setState((current) => ({
                    ...current,
                    online: false,
                    message:
                        'Offline — kailangan ng internet para magsumite ng form.',
                }));
            }
        };
        window.addEventListener('online', connection);
        window.addEventListener('offline', connection);
        document.addEventListener('submit', blockSubmit, true);
        window.addEventListener('kb-certificate-queue', queueChanged);
        const syncTimer = setInterval(() => {
            if (
                page.component.startsWith('Resident/') &&
                networkAvailable &&
                navigator.onLine &&
                preparedScope
            )
                void syncNow();
        }, 30000);
        navigator.serviceWorker?.addEventListener('message', workerMessage);
        setState((current) => ({ ...current, online: navigator.onLine }));
        if ('serviceWorker' in navigator && !import.meta.env.DEV) {
            void (async () => {
                try {
                    await navigator.serviceWorker.register(
                        '/service-worker.js',
                        { scope: '/', updateViaCache: 'none' },
                    );
                    const registration = await navigator.serviceWorker.ready;
                    if (!navigator.serviceWorker.controller) {
                        await new Promise<void>((resolve) => {
                            const changed = () => {
                                clearTimeout(timeout);
                                navigator.serviceWorker.removeEventListener(
                                    'controllerchange',
                                    changed,
                                );
                                resolve();
                            };
                            const timeout = setTimeout(changed, 3000);
                            navigator.serviceWorker.addEventListener(
                                'controllerchange',
                                changed,
                            );
                        });
                    }
                    if (closed) return;
                    worker =
                        navigator.serviceWorker.controller ||
                        registration.active;
                    await fetch('/offline/session', {
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    schedule();
                    void prepareQueue();
                } catch {
                    if (!closed)
                        setState((current) => ({
                            ...current,
                            message:
                                'Hindi available ang offline saving sa browser na ito.',
                        }));
                }
            })();
        }
        return () => {
            closed = true;
            clearTimeout(timer);
            observe.disconnect();
            stopSuccess();
            stopNavigate();
            stopBefore();
            window.removeEventListener('online', connection);
            window.removeEventListener('offline', connection);
            document.removeEventListener('submit', blockSubmit, true);
            window.removeEventListener('kb-certificate-queue', queueChanged);
            clearInterval(syncTimer);
            navigator.serviceWorker?.removeEventListener(
                'message',
                workerMessage,
            );
        };
    }, [initialPage]);

    if (!privatePage) return null;
    // Temporarily hide the shared panel for every module while background offline support remains active.
    return (
        <aside
            hidden
            data-kb-offline-control
            role="status"
            aria-live="polite"
            className={`fixed bottom-3 left-3 z-[80] max-w-[calc(100vw-6rem)] rounded-xl border px-3 py-2 text-xs shadow-lg ${state.online ? 'border-stone-200 bg-white text-stone-700' : 'border-amber-300 bg-amber-50 text-amber-950'}`}
        >
            <p className="font-semibold">
                {!state.online
                    ? 'Offline · saved pages'
                    : state.ready
                      ? 'Saved for offline'
                      : 'Preparing offline copy…'}
            </p>
            {state.savedAt && (
                <p className="mt-1">
                    Huling na-save:{' '}
                    {new Date(state.savedAt).toLocaleTimeString('en-PH', {
                        timeZone: 'Asia/Manila',
                        hour: 'numeric',
                        minute: '2-digit',
                    })}
                </p>
            )}
            {state.message && <p className="mt-1">{state.message}</p>}
            {!state.online && (
                <p className="mt-1">
                    {isResident
                        ? 'Use offline drafts to prepare a certificate request.'
                        : 'Internet is required for live updates.'}
                </p>
            )}
            <a
                href="/offline.html"
                className="mt-1 inline-block font-semibold underline"
            >
                Mga saved page
            </a>
            {isResident && (
                <div className="mt-2 border-t pt-2">
                    <a
                        href="/offline-certificates.html"
                        className="font-semibold underline"
                    >
                        Offline drafts & requests
                        {pendingCertificates > 0
                            ? ` (${pendingCertificates} pending)`
                            : ''}
                    </a>
                    {queueMessage && <p className="mt-1">{queueMessage}</p>}
                    <button
                        type="button"
                        onClick={() => void syncNow()}
                        disabled={!state.online || syncing}
                        className="mt-1 rounded border px-2 py-1 disabled:opacity-50"
                    >
                        {syncing ? 'Syncing…' : 'Sync now'}
                    </button>
                </div>
            )}
        </aside>
    );
}
