import { router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';

const SIGNAL = 'kabarangay.session-boundary.v1';
const logoutPaths = ['/logout', '/resident/logout'];

/** Invalidate local history and already-rendered pages across the same-origin tabs. */
export function protectSession(initialPage: Page) {
    let page = initialPage;
    let loggingOut = false;
    let locked = false;
    let active = true;
    let checkEpoch = 0;
    const readSignal = () => { try { return localStorage.getItem(SIGNAL); } catch { return null; } };
    let observed = readSignal();
    const scope = () => typeof page.props.offlineScope === 'string' ? page.props.offlineScope : null;
    const channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(SIGNAL);
    let cover: HTMLStyleElement | null = null;
    const hide = () => {
        if (cover) return;
        cover = document.createElement('style');
        cover.textContent = '#app { visibility: hidden !important; }';
        document.head.appendChild(cover);
    };
    const lock = () => {
        if (!active || !scope() || loggingOut || locked) return;
        locked = true;
        router.clearHistory();
        // Cover the old DOM synchronously, before any network or redirect completes.
        hide();
        location.replace(navigator.onLine ? (page.component.startsWith('Resident/') ? '/resident/login' : '/staff/login') : '/offline.html?loggedout=1');
    };
    const receive = (value: unknown) => {
        if (typeof value !== 'string') return;
        observed = value;
        try {
            const signal = JSON.parse(value);
            if (signal.kind === 'logout' || (signal.kind === 'account' && signal.scope !== scope())) lock();
        } catch { /* Ignore unrelated or malformed storage events. */ }
    };
    const publish = (kind: 'logout' | 'account', newScope: string | null) => {
        const value = JSON.stringify({ kind, scope: newScope, id: crypto.randomUUID() });
        observed = value;
        try { localStorage.setItem(SIGNAL, value); } catch { /* BroadcastChannel remains available. */ }
        channel?.postMessage(value);
    };
    const stopBefore = router.on('before', event => {
        if (event.detail.visit.method.toLowerCase() !== 'post' || !logoutPaths.includes(event.detail.visit.url.pathname)) return;
        loggingOut = true;
        router.clearHistory();
        publish('logout', null);
    });
    const stopNavigate = router.on('navigate', event => {
        const previousScope = scope();
        page = event.detail.page;
        loggingOut = false;
        if (scope() && scope() !== previousScope) publish('account', scope());
    });
    const storage = (event: StorageEvent) => { if (event.key === SIGNAL) receive(event.newValue); };
    const restore = (event?: Event) => {
        if (event?.type === 'pageshow' && (event as PageTransitionEvent).persisted && loggingOut) {
            loggingOut = false;
            lock();
        }
        const latest = readSignal();
        if (latest && latest !== observed) receive(latest);
        if (locked) return;
        const expectedScope = scope();
        if (!expectedScope || loggingOut || document.visibilityState === 'hidden') return;
        // Also detect expiry or logout from another origin sharing the session cookie.
        // Only conceal a restored document. Routine focus checks must not blank
        // a working page while the server is slow or temporarily unavailable.
        if (event?.type === 'pageshow' && (event as PageTransitionEvent).persisted) hide();
        const check = ++checkEpoch;
        void fetch('/offline/session', { signal: AbortSignal.timeout(8000), credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(async response => {
                if (!response.ok) throw new Error('Session check unavailable');
                const session = await response.json();
                if (active && scope() === expectedScope && session.scope !== expectedScope) lock();
            })
            .catch(() => { /* Keep the existing offline policy when the network is unavailable. */ })
            .finally(() => { if (active && !locked && check === checkEpoch) { cover?.remove(); cover = null; } });
    };
    const workerMessage = (event: MessageEvent) => {
        if (event.data?.type === 'KB_PRIVATE_LOCKED' && event.data.scope === scope()) lock();
    };
    const message = (event: MessageEvent) => receive(event.data);
    channel?.addEventListener('message', message);
    window.addEventListener('storage', storage);
    const pagehide = () => { if (scope()) hide(); };
    window.addEventListener('pagehide', pagehide);
    window.addEventListener('pageshow', restore);
    window.addEventListener('focus', restore);
    document.addEventListener('visibilitychange', restore);
    navigator.serviceWorker?.addEventListener('message', workerMessage);
    return () => {
        active = false;
        stopBefore(); stopNavigate(); channel?.close();
        window.removeEventListener('storage', storage);
        window.removeEventListener('pagehide', pagehide);
        window.removeEventListener('pageshow', restore);
        cover?.remove();
        window.removeEventListener('focus', restore);
        document.removeEventListener('visibilitychange', restore);
        navigator.serviceWorker?.removeEventListener('message', workerMessage);
    };
}
