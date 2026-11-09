import OfflineConnectivityIndicator from '@/components/OfflineConnectivityIndicator';
import { protectSession } from '@/lib/protectedSession';
import { router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { useEffect, useState } from 'react';
import {
    notifyConnectivity,
    updateConnectivity,
} from '@/lib/offlineConnectivity';
import { offlineSnapshot } from '@/lib/offlineSnapshot';
import {
    UNSYNCED_STATUSES,
    offlineCertificateStore,
    prepareOfflineCertificates,
} from '@/lib/offlineCertificates';

const PROBE_MS = 25000;
const PROBE_RETRY_MS = 1000;

type WorkerReply = { ok: boolean; savedAt?: number };
const eligible = (page: Page) =>
    typeof page.props.offlineScope === 'string' &&
    /^(Resident|Admin|Clerk|Lupon|Pwd|Senior)\//i.test(page.component) &&
    !/\/(Login|Register|Registration|ForgotPassword|ResetPassword|Verify)/i.test(
        page.component,
    );

export default function OfflineSupport({ initialPage }: { initialPage: Page }) {
    useEffect(() => protectSession(initialPage), [initialPage]);
    const [privatePage, setPrivatePage] = useState(eligible(initialPage));
    const countPending = (items: { status: string }[]) =>
        items.filter((item) => UNSYNCED_STATUSES.includes(item.status)).length;
    const syncNow = async () => {
        updateConnectivity({ syncing: true });
        const before = await offlineCertificateStore()
            .then(async (store) => countPending(await store.list()))
            .catch(() => 0);
        try {
            const store = await offlineCertificateStore();
            const result = await store.sync();
            const after = countPending(await store.list());
            updateConnectivity({ pending: after });
            if (after < before) {
                notifyConnectivity(
                    'success',
                    after === 0
                        ? 'Your request has been sent successfully.'
                        : `${before - after} request${before - after === 1 ? '' : 's'} sent successfully. The rest are still waiting.`,
                );
                if (location.pathname.startsWith('/resident/certificates'))
                    router.reload({ only: ['requests'] });
            } else if (result.loginRequired)
                notifyConnectivity(
                    'error',
                    'Sign in online to send your saved requests.',
                );
            else if (after > 0 && (result.offline || result.retryLater))
                notifyConnectivity(
                    'error',
                    'Your requests are still saved on this device. They will send automatically.',
                );
        } catch (error) {
            notifyConnectivity(
                'error',
                error instanceof Error
                    ? error.message
                    : 'Offline requests are not ready.',
            );
        } finally {
            updateConnectivity({ syncing: false });
        }
    };

    useEffect(() => {
        let page = initialPage;
        let worker: ServiceWorker | null = null;
        let closed = false;
        let loggingOut = false;
        let networkAvailable = navigator.onLine;
        let dataAvailable = true;
        let preparedScope: unknown = null;
        let preparing = false;
        const isOnline = () =>
            navigator.onLine && networkAvailable && dataAvailable;
        const publish = () =>
            updateConnectivity({
                online: isOnline(),
                degraded: networkAvailable && !dataAvailable,
            });
        let probeFailures = 0;
        let activeProbe: AbortController | undefined;
        let probeRetry: ReturnType<typeof setTimeout> | undefined;
        const cancelProbe = () => {
            clearTimeout(probeRetry);
            activeProbe?.abort();
            activeProbe = undefined;
        };
        const probe = async () => {
            if (closed || !navigator.onLine || activeProbe) return;
            clearTimeout(probeRetry);
            const controller = new AbortController();
            activeProbe = controller;
            const abort = setTimeout(() => controller.abort(), 8000);
            let identity: 'verified' | 'anonymous' | 'unresolved' | null = null;
            try {
                const response = await fetch('/offline/session', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                });
                const session = (await response.json().catch(() => null)) as {
                    identity?: unknown;
                    scope?: unknown;
                } | null;
                // Older servers return a scope without an identity field.
                if (response.ok && session) {
                    if (
                        session.identity === 'verified' ||
                        session.identity === 'anonymous'
                    ) {
                        identity = session.identity;
                    } else if (
                        session.identity === undefined &&
                        session.scope === null
                    ) {
                        identity = 'anonymous';
                    } else if (
                        session.identity === undefined &&
                        typeof session.scope === 'string' &&
                        /^[a-f0-9]{64}$/.test(session.scope)
                    ) {
                        identity = 'verified';
                    }
                }
                identity ??= 'unresolved';
            } catch {
                identity = null;
            } finally {
                clearTimeout(abort);
            }
            if (activeProbe !== controller || closed) return;
            activeProbe = undefined;
            if (!navigator.onLine) return;
            const recovered = !networkAvailable || !dataAvailable;
            // Connectivity and identity are separate measurements. Any answer proves this
            // server is up; only an identity that had to read the account tables proves
            // the data service is readable enough to trust a submission.
            if (identity === 'verified') {
                probeFailures = 0;
                networkAvailable = true;
                dataAvailable = true;
                publish();
                if (recovered) restore();
                return;
            }
            if (identity === 'anonymous') {
                // A signed-out probe never touches the account tables, so it confirms
                // reachability and nothing about the data service. It may relax the
                // offline gate only where there is no authenticated context to queue
                // for, and it must never be recorded as proof that submissions are safe
                // while a private page — and therefore a live session — is still open.
                probeFailures = 0;
                networkAvailable = true;
                if (!eligible(page)) dataAvailable = true;
                publish();
                return;
            }
            if (identity === null) {
                // navigator.onLine stays true on captive portals and dead uplinks.
                if (++probeFailures < 2) {
                    probeRetry = setTimeout(() => void probe(), PROBE_RETRY_MS);
                    return;
                }
                networkAvailable = false;
                dataAvailable = false;
                publish();
                return;
            }
            // 'unresolved': Laravel is answering but cannot read account state, so the
            // device must queue instead of submitting. One answer is enough proof.
            probeFailures = 0;
            networkAvailable = true;
            dataAvailable = false;
            // Always published: a reconnect that lands on an outage has to move the copy
            // off "your internet is down" even though queueing was already blocked.
            publish();
        };
        const restore = () => {
            schedule();
            void prepareQueue().then(() => {
                if (page.component.startsWith('Resident/')) void syncNow();
            });
        };
        const prepareQueue = async () => {
            if (
                !worker ||
                preparing ||
                !isOnline() ||
                !eligible(page) ||
                !page.component.startsWith('Resident/')
            )
                return;
            if (preparedScope === page.props.offlineScope) return;
            preparing = true;
            try {
                await prepareOfflineCertificates();
                preparedScope = page.props.offlineScope;
                if (!closed) void syncNow();
            } catch {
                // Preparation needs one online visit; nothing to queue yet.
            } finally {
                preparing = false;
            }
        };
        const changedPage = (next: Page) => {
            page = next;
            setPrivatePage(eligible(page));
            updateConnectivity({
                pending: 0,
                stale: page.props.offlineCached === true,
            });
            if (!isOnline() && page.props.offlineCached !== true) void probe();
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
                !isOnline() ||
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
                await tell({
                    type: 'KB_SAVE',
                    scope,
                    path: location.pathname + location.search,
                    title: document.title,
                    html: offlineSnapshot(Date.now()),
                    assets,
                });
            } catch {
                // Snapshots are best effort; the app keeps working without one.
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
            if (isOnline()) return;
            if (visit.method.toLowerCase() === 'get') {
                // Let the visit continue: the service worker answers it from the
                // authorized page cache, and falls back to a saved copy when absent.
                return;
            }
            notifyConnectivity(
                'error',
                networkAvailable
                    ? 'The barangay data service is unavailable, so this action was not saved.'
                    : 'Internet connection required to save this action.',
            );
            return false;
        });
        const connection = () => {
            if (!navigator.onLine) {
                cancelProbe();
                networkAvailable = false;
                dataAvailable = false;
                probeFailures = 0;
                publish();
                return;
            }
            // Reconnected, but reachability and readable account state still have to
            // be proven before cached pages or queues are trusted again.
            probeFailures = 0;
            void probe();
        };
        const queueChanged = () => {
            if (!page.component.startsWith('Resident/')) return;
            void offlineCertificateStore()
                .then((store) => store.list())
                .then((items) => {
                    if (!closed)
                        updateConnectivity({ pending: countPending(items) });
                })
                .catch(() => {});
        };
        const workerMessage = (event: MessageEvent) => {
            if (event.data?.type === 'KB_NETWORK_OFFLINE') {
                // A single failed or cancelled request can trigger this message.
                // Verify reachability before changing the state of the whole app.
                if (navigator.onLine) {
                    if (!activeProbe && probeFailures === 0) void probe();
                } else {
                    connection();
                }
            }
            if (event.data?.type === 'KB_DATA_UNAVAILABLE') {
                networkAvailable = true;
                dataAvailable = false;
                publish();
            }
            if (event.data?.type === 'KB_CERT_QUEUE_CHANGED') queueChanged();
        };
        const blockSubmit = (event: Event) => {
            const form = event.target as HTMLFormElement | null;
            // Forms marked as offline-queueable handle their own offline submit.
            if (isOnline() || form?.dataset?.kbOfflineQueue !== undefined)
                return;
            event.preventDefault();
            event.stopImmediatePropagation();
            notifyConnectivity(
                'error',
                networkAvailable
                    ? 'The barangay data service is unavailable, so nothing was submitted.'
                    : 'Internet connection required to submit this form.',
            );
        };
        window.addEventListener('online', connection);
        window.addEventListener('offline', connection);
        document.addEventListener('submit', blockSubmit, true);
        window.addEventListener('kb-certificate-queue', queueChanged);
        const syncTimer = setInterval(() => {
            if (
                page.component.startsWith('Resident/') &&
                isOnline() &&
                preparedScope
            )
                void syncNow();
        }, 30000);
        const probeTimer = setInterval(() => {
            if (navigator.onLine) void probe();
        }, PROBE_MS);
        navigator.serviceWorker?.addEventListener('message', workerMessage);
        publish();
        void probe();
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
                        notifyConnectivity(
                            'error',
                            'This browser cannot save offline copies.',
                        );
                }
            })();
        }
        return () => {
            closed = true;
            cancelProbe();
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
            clearInterval(probeTimer);
            navigator.serviceWorker?.removeEventListener(
                'message',
                workerMessage,
            );
        };
    }, [initialPage]);

    if (!privatePage) return null;
    return <OfflineConnectivityIndicator onRetry={() => void syncNow()} />;
}
