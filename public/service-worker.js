/* Read-only saved pages plus an account-bound certificate submission queue. */
importScripts('/offline-certificate-store.js');
importScripts('/offline-attendance-store.js');
const VERSION = 'kb-offline-v1';
const ASSETS = VERSION + '-assets';
const META = VERSION + '-meta';
const PAGES = VERSION + '-pages-';
const MAX_AGE = 24 * 60 * 60 * 1000;
const MAX_PAGES = 20;
const OFFLINE_LOGOUT = '/offline.html?loggedout=1';
let serial = Promise.resolve();
let authEpoch = 0;
const ordered = (work) => {
    const result = serial.then(work, work);
    serial = result.catch(() => {});
    return result;
};
const metadata = async () => {
    const cache = await caches.open(META);
    const item = await cache.match('/__kb_offline_state');
    return item ? item.json() : { scope: null, pendingLogout: null };
};
const writeMetadata = async (value) =>
    (await caches.open(META)).put('/__kb_offline_state', Response.json(value));
const clearPages = async () => {
    await self.KBCertificates.lock().catch(() => {});
    await self.KBAttendance.lock().catch(() => {});
    const previousScope = (await metadata()).scope;
    for (const client of await self.clients.matchAll({ type: 'window' }))
        if (previousScope) client.postMessage({ type: 'KB_PRIVATE_LOCKED', scope: previousScope });
    return Promise.all(
        (await caches.keys())
            .filter((key) => key.startsWith(PAGES))
            .map((key) => caches.delete(key)),
    );
};
const pageKey = (path) => '/__kb_offline_page/' + encodeURIComponent(path);
const pagePath = (input) => {
    const url = new URL(input, self.location.origin);
    if (
        url.origin !== self.location.origin ||
        !/^\/(?:resident(?:\/|$)|admin(?:\/|$)|clerk(?:\/|$)|lupon(?:\/|$)|pwd(?:\/|$)|senior(?:\/|$))/.test(
            url.pathname,
        )
    )
        return null;
    if (
        /(?:^|\/)(?:login|logout|register|application|forgot-password|reset-password|email|verify|documents?|evidence|download|file|print|qr)(?:\/|$)/.test(
            url.pathname,
        )
    )
        return null;
    return url.pathname + url.search;
};
const assetPath = (input) => {
    const url = new URL(input, self.location.origin);
    return (
        url.origin === self.location.origin &&
        (/^\/build\/assets\/[\w.~-]+\.(?:css|woff2?|png|svg|ico)$/.test(
            url.pathname,
        ) ||
            [
                '/offline-viewer.js',
                '/offline-certificate-store.js',
                '/offline-certificates.js',
                '/offline-attendance-store.js',
                '/offline-attendance.js',
                '/favicon.ico',
                '/favicon.svg',
                '/apple-touch-icon.png',
            ].includes(url.pathname))
    );
};
const setScope = async (scope) => {
    const state = await metadata();
    if (state.pendingLogout) return;
    if (state.scope !== scope) {
        authEpoch++;
        await clearPages();
        await writeMetadata({ scope, pendingLogout: null });
    }
};
const logout = async (path) => {
    authEpoch++;
    await clearPages();
    const state = await metadata();
    await writeMetadata({
        scope: null,
        pendingLogout: path || state.pendingLogout,
    });
};

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(ASSETS);
            for (const path of [
                '/offline.html',
                '/offline-viewer.js',
                '/offline-certificates.html',
                '/offline-certificate-store.js',
                '/offline-certificates.js',
                '/offline-attendance.html',
                '/offline-attendance-store.js',
                '/offline-attendance.js',
            ]) {
                const response = await fetch(path, { cache: 'reload' });
                if (!response.ok)
                    throw new Error('Offline support files are unavailable.');
                await cache.put(path, response);
            }
            await self.skipWaiting();
        })(),
    );
});
self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);
self.addEventListener('sync', (event) => {
    if (event.tag !== 'kb-certificate-sync') return;
    event.waitUntil(
        (async () => {
            await ordered(finishPendingLogout);
            if ((await metadata()).pendingLogout) return;
            const result = await self.KBCertificates.sync();
            if (result.offline || result.retryLater)
                throw new Error(
                    'Certificate sync will retry when a connection is available.',
                );
        })(),
    );
});

// A logout performed offline must also invalidate the server session before
// any future online request is allowed to resume an authenticated session.
async function finishPendingLogout() {
    const state = await metadata();
    if (!state.pendingLogout) return;
    const session = await fetch('/offline/session', {
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!session.ok) throw new Error('Unable to finish logout.');
    const data = await session.json();
    const response = await fetch(state.pendingLogout, {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'follow',
        headers: { 'X-CSRF-TOKEN': data.csrfToken, Accept: 'text/html' },
    });
    if (!response.ok) throw new Error('Unable to finish logout.');
    await writeMetadata({ scope: null, pendingLogout: null });
}

async function savedPage(path) {
    const { scope, pendingLogout } = await metadata();
    if (!scope || pendingLogout || !path) return null;
    const cache = await caches.open(PAGES + scope);
    const response = await cache.match(pageKey(path));
    if (!response) return null;
    const item = await response.json();
    if (Date.now() - item.savedAt > MAX_AGE) {
        await cache.delete(pageKey(path));
        return null;
    }
    return new Response(item.html, {
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
            'X-KaBarangay-Offline': '1',
            'Content-Security-Policy':
                "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'",
        },
    });
}

async function fallback(request) {
    for (const client of await self.clients.matchAll({ type: 'window' }))
        client.postMessage({ type: 'KB_NETWORK_OFFLINE' });
    if (request.mode === 'navigate') {
        const copy = await savedPage(pagePath(request.url));
        if (new URL(request.url).pathname === '/offline-certificates.html')
            return (await caches.open(ASSETS)).match(
                '/offline-certificates.html',
            );
        if (new URL(request.url).pathname === '/offline-attendance.html')
            return (await caches.open(ASSETS)).match(
                '/offline-attendance.html',
            );
        return copy || (await caches.open(ASSETS)).match('/offline.html');
    }
    if (request.headers.get('X-Inertia') === 'true') {
        if (
            request.method === 'GET' &&
            request.headers.get('X-Inertia-Partial-Component')
        ) {
            // Keep already loaded props during a failed deferred/poll request.
            return Response.json(
                {
                    component: request.headers.get(
                        'X-Inertia-Partial-Component',
                    ),
                    props: {},
                    url:
                        new URL(request.url).pathname +
                        new URL(request.url).search,
                    version: request.headers.get('X-Inertia-Version') || null,
                },
                {
                    headers: {
                        'X-Inertia': 'true',
                        'Cache-Control': 'no-store',
                    },
                },
            );
        }
        return new Response(null, {
            status: 409,
            headers: {
                'X-Inertia-Location':
                    request.method === 'GET'
                        ? request.url
                        : '/offline.html?write-unconfirmed=1',
            },
        });
    }
    return Response.json(
        { error: 'Offline. Reconnect to load or update this information.' },
        { status: 503 },
    );
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (assetPath(url.href) && request.method === 'GET') {
        event.respondWith(
            (async () => {
                const cache = await caches.open(ASSETS);
                const cached = await cache.match(request);
                if (cached) return cached;
                const response = await fetch(request);
                if (response.ok) await cache.put(request, response.clone());
                return response;
            })(),
        );
        return;
    }
    event.respondWith(
        (async () => {
            const requestEpoch = authEpoch;
            try {
                const isLogout =
                    request.method === 'POST' &&
                    ['/logout', '/resident/logout'].includes(url.pathname);
                if (isLogout) {
                    await ordered(() => logout(url.pathname));
                    let response;
                    try {
                        response = await fetch(request);
                    } catch {
                        return request.headers.get('X-Inertia') === 'true'
                            ? new Response(null, {
                                  status: 409,
                                  headers: {
                                      'X-Inertia-Location': OFFLINE_LOGOUT,
                                  },
                              })
                            : Response.redirect(
                                  self.location.origin + OFFLINE_LOGOUT,
                                  303,
                              );
                    }
                    if (response.ok)
                        await ordered(() =>
                            writeMetadata({ scope: null, pendingLogout: null }),
                        );
                    return response;
                }
                await ordered(finishPendingLogout);
                const response = await fetch(request);
                if (response.status === 401 || response.status === 419) {
                    await ordered(async () => {
                        if (requestEpoch !== authEpoch) return;
                        authEpoch++;
                        await clearPages();
                        await writeMetadata({
                            scope: null,
                            pendingLogout: null,
                        });
                    });
                } else if (
                    response.ok &&
                    response.headers.has('X-KaBarangay-Offline-Scope')
                ) {
                    const scope =
                        response.headers.get('X-KaBarangay-Offline-Scope') ||
                        null;
                    await ordered(() =>
                        requestEpoch === authEpoch
                            ? setScope(scope)
                            : undefined,
                    );
                }
                return response;
            } catch {
                return fallback(request);
            }
        })(),
    );
});

self.addEventListener('message', (event) => {
    const reply = (value) => event.ports[0]?.postMessage(value);
    event.waitUntil(
        ordered(async () => {
            try {
                const data = event.data || {};
                if (data.type === 'KB_LOGOUT') {
                    await logout(
                        data.path === '/logout'
                            ? '/logout'
                            : '/resident/logout',
                    );
                    reply({ ok: true });
                    return;
                }
                const state = await metadata();
                if (data.type === 'KB_CERT_ACTIVATE') {
                    if (
                        !state.scope ||
                        state.pendingLogout ||
                        data.context?.scope !== state.scope
                    ) {
                        reply({ ok: false });
                        return;
                    }
                    await self.KBCertificates.activate(data.context);
                    reply({ ok: true });
                    return;
                }
                if (data.type === 'KB_ATTENDANCE_ACTIVATE') {
                    if (
                        !state.scope ||
                        state.pendingLogout ||
                        data.context?.scope !== state.scope
                    ) {
                        reply({ ok: false });
                        return;
                    }
                    await self.KBAttendance.activate(data.context);
                    reply({ ok: true });
                    return;
                }
                if (data.type === 'KB_LIST') {
                    const pages = [];
                    if (state.scope && !state.pendingLogout) {
                        const cache = await caches.open(PAGES + state.scope);
                        for (const key of await cache.keys()) {
                            const item = await (await cache.match(key)).json();
                            if (Date.now() - item.savedAt <= MAX_AGE)
                                pages.push({
                                    path: item.path,
                                    title: item.title,
                                    savedAt: item.savedAt,
                                });
                            else await cache.delete(key);
                        }
                    }
                    reply({
                        ok: true,
                        pages: pages.sort((a, b) => b.savedAt - a.savedAt),
                        pendingLogout: Boolean(state.pendingLogout),
                    });
                    return;
                }
                if (
                    data.type !== 'KB_SAVE' ||
                    !state.scope ||
                    state.pendingLogout ||
                    data.scope !== state.scope
                ) {
                    reply({ ok: false });
                    return;
                }
                const path = pagePath(data.path);
                if (
                    !path ||
                    typeof data.html !== 'string' ||
                    data.html.length > 1500000
                ) {
                    reply({ ok: false });
                    return;
                }
                const assets = [
                    ...new Set(Array.isArray(data.assets) ? data.assets : []),
                ]
                    .filter(assetPath)
                    .slice(0, 180);
                const assetCache = await caches.open(ASSETS);
                // Cache the resources already used by this page before claiming it is ready.
                await Promise.all(
                    assets.map(async (asset) => {
                        if (await assetCache.match(asset)) return;
                        const response = await fetch(asset, {
                            credentials: 'same-origin',
                        });
                        if (!response.ok)
                            throw new Error('A page resource is unavailable.');
                        await assetCache.put(asset, response);
                    }),
                );
                const pages = await caches.open(PAGES + state.scope);
                const savedAt = Date.now();
                await pages.delete(pageKey(path));
                await pages.put(
                    pageKey(path),
                    Response.json({
                        path,
                        title: String(data.title || 'KaBarangay').slice(0, 180),
                        html: data.html,
                        savedAt,
                    }),
                );
                const keys = await pages.keys();
                for (const key of keys.slice(
                    0,
                    Math.max(0, keys.length - MAX_PAGES),
                ))
                    await pages.delete(key);
                reply({ ok: true, savedAt });
            } catch {
                reply({ ok: false });
            }
        }),
    );
});
