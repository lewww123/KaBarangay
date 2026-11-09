/* Read-only saved pages plus an account-bound certificate submission queue. */
importScripts('/offline-certificate-store.js');
importScripts('/offline-attendance-store.js');

const VERSION = 'kb-offline-v2';
const ASSETS = VERSION + '-assets';
const META = VERSION + '-meta';
const PAGES = VERSION + '-pages-';
const SHELL = VERSION + '-shell-';
const MAX_AGE = 24 * 60 * 60 * 1000;
const MAX_PAGES = 20;
const MAX_SHELL_BYTES = 2200000;
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

const clearScopedCaches = async () => {
    await self.KBCertificates.lock().catch(() => {});
    await self.KBAttendance.lock().catch(() => {});
    const previousScope = (await metadata()).scope;
    for (const client of await self.clients.matchAll({ type: 'window' }))
        if (previousScope)
            client.postMessage({ type: 'KB_PRIVATE_LOCKED', scope: previousScope });
    return Promise.all(
        (await caches.keys())
            .filter((key) => key.startsWith(PAGES) || key.startsWith(SHELL))
            .map((key) => caches.delete(key)),
    );
};

const pageKey = (path) => '/__kb_offline_page/' + encodeURIComponent(path);
const shellKey = (path) => '/__kb_shell_page/' + encodeURIComponent(path);
const propsKey = (path) => '/__kb_inertia_props/' + encodeURIComponent(path);
const MAX_PROPS_BYTES = 1000000;

// Authorized page data, kept per scope so the live app can navigate offline.
async function saveProps(path, partial) {
    const state = await metadata();
    if (!state.scope || state.pendingLogout) return;
    const cache = await caches.open(PAGES + state.scope);
    const previous = await cache.match(propsKey(path));
    const merged = {
        ...(previous ? (await previous.json()).props : {}),
        ...partial.props,
    };
    const entry = Response.json({
        path,
        props: merged,
        component: partial.component,
        savedAt: Date.now(),
    });
    if ((await entry.clone().text()).length > MAX_PROPS_BYTES) return;
    await cache.put(propsKey(path), entry);
}

async function cachedProps(path) {
    const { scope, pendingLogout } = await metadata();
    if (!scope || pendingLogout) return null;
    const cache = await caches.open(PAGES + scope);
    const found = await cache.match(propsKey(path));
    if (!found) return null;
    const item = await found.json();
    if (Date.now() - item.savedAt > MAX_AGE) {
        await cache.delete(propsKey(path));
        return null;
    }
    return item;
}

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

// Only the approved offline workflow may keep its page data for live offline use.
const propsPath = (input) => {
    const path = pagePath(input);
    return path && /^\/resident\/certificates(?:\?.*)?$/.test(path) ? path : null;
};

const cacheableAssetResponse = (path, response) => {
    if (!response || !response.ok) return false;
    const type = (response.headers.get('Content-Type') || '').toLowerCase();
    if (/^\/build\/assets\/.+\.(?:css)$/i.test(path)) return type.includes('text/css') || type.includes('javascript') === false;
    if (/^\/build\/assets\/.+\.(?:js|mjs)$/i.test(path))
        return !type.includes('text/html');
    if (type.includes('text/html') && /^\/build\//.test(path)) return false;
    return true;
};

const assetPath = (input) => {
    const url = new URL(input, self.location.origin);
    if (url.origin !== self.location.origin) return false;
    if (
        /^\/build\/assets\/[\w.~-]+\.(?:css|js|mjs|woff2?|png|svg|ico|webp)$/.test(
            url.pathname,
        )
    )
        return true;
    return [
        '/offline-viewer.js',
        '/offline-certificate-store.js',
        '/offline-certificates.js',
        '/offline-attendance-store.js',
        '/offline-attendance.js',
        '/favicon.ico',
        '/favicon.svg',
        '/apple-touch-icon.png',
    ].includes(url.pathname);
};

const setScope = async (scope) => {
    const state = await metadata();
    if (state.pendingLogout) return;
    if (state.scope !== scope) {
        authEpoch++;
        await clearScopedCaches();
        await writeMetadata({ scope, pendingLogout: null });
    }
};

const logout = async (path) => {
    authEpoch++;
    await clearScopedCaches();
    const state = await metadata();
    await writeMetadata({
        scope: null,
        pendingLogout: path || state.pendingLogout,
    });
};

async function coreShellAssets() {
    const fallback = [
        '/offline.html',
        '/offline-viewer.js',
        '/offline-certificates.html',
        '/offline-certificate-store.js',
        '/offline-certificates.js',
        '/offline-attendance.html',
        '/offline-attendance-store.js',
        '/offline-attendance.js',
    ];
    try {
        const req = new Request(self.location.origin + '/build/manifest.json');
        const response = await fetch(req, {
            cache: 'no-store',
            credentials: 'same-origin',
        });
        if (!response.ok) return fallback;
        if ((response.headers.get('Content-Type') || '').includes('json'))
            await (await caches.open(ASSETS)).put(req, response.clone()).catch(() => {});
        const manifest = await response.clone().json();
        const app = manifest['resources/js/app.tsx'];
        const css = manifest['resources/css/app.css'];
        const files = new Set(fallback);
        const addKey = (key) => {
            const entry = manifest[key];
            if (!entry) return;
            if (entry.file) files.add('/build/' + entry.file);
            for (const extra of entry.css || []) files.add('/build/' + extra);
            for (const child of entry.imports || []) addKey(child);
        };
        addKey('resources/js/app.tsx');
        if (app?.css) for (const extra of app.css) files.add('/build/' + extra);
        if (css?.file) files.add('/build/' + css.file);
        return [...files];
    } catch {
        return fallback;
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(ASSETS);
            const paths = await coreShellAssets();
            for (const path of paths) {
                try {
                    const response = await fetch(path, {
                        cache: 'reload',
                        credentials: 'same-origin',
                    });
                    if (cacheableAssetResponse(path, response))
                        await cache.put(path, response);
                } catch {
                    // Best effort for core shell assets; offline support files below must exist.
                }
            }
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
                if (await cache.match(path)) continue;
                const response = await fetch(path, {
                    cache: 'reload',
                    credentials: 'same-origin',
                });
                if (!response.ok)
                    throw new Error('Offline support files are unavailable.');
                await cache.put(path, response);
            }
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) =>
    event.waitUntil(
        (async () => {
            const keep = new Set([ASSETS, META]);
            for (const key of await caches.keys()) {
                if (
                    keep.has(key) ||
                    key.startsWith(PAGES) ||
                    key.startsWith(SHELL)
                )
                    continue;
                if (key.startsWith('kb-offline-')) await caches.delete(key);
            }
            await self.clients.claim();
        })(),
    ),
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

let cachedCoreEntry = null;
async function hasCoreShell() {
    try {
        if (cachedCoreEntry) {
            const cached = await (await caches.open(ASSETS)).match(cachedCoreEntry);
            return Boolean(cached && cacheableAssetResponse('/build/' + cachedCoreEntry.split('/build/').pop(), cached));
        }
        const req = new Request(self.location.origin + '/build/manifest.json');
        const response =
            (await (await caches.open(ASSETS)).match(req)) ||
            (await caches.match(req)) ||
            (await fetch(req, { credentials: 'same-origin', cache: 'no-store' }).catch(() => null));
        if (!response || !response.ok) return false;
        if (!(await (await caches.open(ASSETS)).match(req))) {
            try {
                await (await caches.open(ASSETS)).put(req, response.clone());
            } catch {}
        }
        const manifest = await response.clone().json();
        const app = manifest['resources/js/app.tsx'];
        const entry = app?.file ? self.location.origin + '/build/' + app.file : null;
        if (!entry) return false;
        cachedCoreEntry = entry;
        return Boolean(await (await caches.open(ASSETS)).match(entry));
    } catch {
        return false;
    }
}

async function shellPage(path) {
    const { scope, pendingLogout } = await metadata();
    if (!scope || pendingLogout || !path) return null;
    if (!(await hasCoreShell())) return null;
    const cache = await caches.open(SHELL + scope);
    const response = await cache.match(shellKey(path));
    if (!response) return null;
    const item = await response.json();
    if (Date.now() - item.savedAt > MAX_AGE) {
        await cache.delete(shellKey(path));
        return null;
    }
    return new Response(item.html, {
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
            'X-KaBarangay-Offline': 'shell',
        },
    });
}

async function saveShellPage(path, html) {
    const state = await metadata();
    if (!state.scope || state.pendingLogout || !path || typeof html !== 'string')
        return;
    if (html.length > MAX_SHELL_BYTES) return;
    const cache = await caches.open(SHELL + state.scope);
    await cache.put(
        shellKey(path),
        Response.json({ path, html, savedAt: Date.now() }),
    );
    const keys = await cache.keys();
    for (const key of keys.slice(0, Math.max(0, keys.length - MAX_PAGES)))
        await cache.delete(key);
}

// Authentication and sensitive account flows must use normal network responses.
function isAuthRequest(request) {
    const url = new URL(request.url, self.location.origin);
    const path = url.pathname;
    if (url.origin !== self.location.origin) return false;
    return (
        path === '/login' ||
        path === '/logout' ||
        path === '/staff/login' ||
        path === '/resident/login' ||
        path === '/resident/login/barangays' ||
        path === '/resident/logout' ||
        path === '/resident/register' ||
        /^\/resident\/register\//.test(path) ||
        path === '/resident/forgot-password' ||
        /^\/resident\/reset-password(?:\/|$)/.test(path) ||
        /^\/resident\/application\/[^/]+\/logout$/.test(path) ||
        /^\/resident\/email\/(?:verification\/resend|verify\/)/.test(path) ||
        /^\/verify\/resident\//.test(path)
    );
}

async function firstShellPath() {
    const { scope, pendingLogout } = await metadata();
    if (!scope || pendingLogout) return null;
    const cache = await caches.open(SHELL + scope);
    const items = [];
    for (const key of await cache.keys()) {
        const item = await (await cache.match(key)).json().catch(() => null);
        if (!item?.path || !item?.savedAt) continue;
        if (Date.now() - item.savedAt > MAX_AGE) {
            await cache.delete(key);
            continue;
        }
        items.push(item);
    }
    items.sort((a, b) => b.savedAt - a.savedAt);
    return items[0]?.path || null;
}

function uncachedNoticeResponse(targetPath, shellAvailable) {
    const path = String(targetPath || '/');
    const title = 'KaBarangay';
    const message = shellAvailable
        ? 'Walang saved na kopya ng page na ito sa device. Buksan ang isang page na na-save mo habang online.'
        : 'Kailangan ng internet connection para ma-load ang KaBarangay sa unang paggamit o kapag wala pang naka-cache na resources sa device na ito.';
    const html = `<!doctype html><html lang="fil"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><title>${title}</title><style>body{margin:0;font:16px/1.6 system-ui,sans-serif;background:#f6f7f9;color:#1f2937}main{max-width:720px;margin:32px auto;padding:20px} .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)} h1{font-size:1.25rem;margin:0 0 8px} p{margin:0 0 12px;color:#374151} .path{font-family:ui-monospace,monospace;font-size:.85rem;background:#f3f4f6;padding:6px 8px;border-radius:8px;word-break:break-all} a,button{display:inline-block;margin-right:10px;margin-top:8px;padding:9px 14px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;color:#111827;text-decoration:none;cursor:pointer} .primary{background:#0f766e;border-color:#0f766e;color:#fff}</style></head><body><main><div class="card"><p style="font-size:.85rem;color:#0f766e;font-weight:600;margin-bottom:6px">KaBarangay · Offline</p><h1>Hindi available offline ang page na ito</h1><p>${message}</p><p class="path">${path.replace(/[<>&"]/g,'')}</p><p style="font-size:.9rem">Hindi live data ang ipapakita mula sa cache. Kailangan ng internet para sa bagong impormasyon, pag-submit, pagbayad, o pag-approve.</p><div><a class="primary" href="/resident/certificates">Buksan ang saved app</a><a href="/resident/login">Resident login</a><a href="/staff/login">Staff login</a><button type="button" onclick="location.reload()">Subukan ulit</button></div></div></main></body></html>`;
    return new Response(html, {
        status: 200,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'no-store',
            'X-KaBarangay-Offline': 'uncached',
        },
    });
}

async function fallback(request, degraded) {
    for (const client of await self.clients.matchAll({ type: 'window' }))
        client.postMessage({
            type: degraded ? 'KB_DATA_UNAVAILABLE' : 'KB_NETWORK_OFFLINE',
        });
    if (request.mode === 'navigate') {
        const path = pagePath(request.url);
        const bootable = await shellPage(path);
        if (bootable) return bootable;
        const copy = await savedPage(path);
        if (copy) return copy;
        const url = new URL(request.url);
        if (url.pathname === '/offline-certificates.html')
            return (await caches.open(ASSETS)).match('/offline-certificates.html');
        if (url.pathname === '/offline-attendance.html')
            return (await caches.open(ASSETS)).match('/offline-attendance.html');
        // Offline logout / special offline viewer routes keep their explicit handling.
        if (url.pathname === '/offline.html' && url.searchParams.get('loggedout') === '1')
            return (await caches.open(ASSETS)).match('/offline.html');
        if (path && (await hasCoreShell())) {
            const target = await firstShellPath();
            if (target) {
                const dest = new URL(target, self.location.origin);
                dest.searchParams.set('offline_uncached', '1');
                dest.searchParams.set('from', path.slice(0, 300));
                return Response.redirect(dest.toString(), 302);
            }
        }
        if (path) return uncachedNoticeResponse(path, await hasCoreShell());
        return uncachedNoticeResponse(url.pathname, await hasCoreShell());
    }
    if (request.headers.get('X-Inertia') === 'true') {
        const partial =
            request.method === 'GET' &&
            request.headers.get('X-Inertia-Partial-Component');
        const path = propsPath(request.url);
        const saved = path ? await cachedProps(path) : null;
        if (saved) {
            // Keep the live app running: the page renders from authorized,
            // scope-partitioned data already loaded on this device.
            let props = saved.props;
            if (partial) {
                const only = (request.headers.get('X-Inertia-Partial-Data') || '')
                    .split(',')
                    .filter((key) => key && key in props);
                props = Object.fromEntries(only.map((key) => [key, saved.props[key]]));
                if (!only.length) props = {};
            }
            return Response.json(
                {
                    component: saved.component,
                    props: { ...props, offlineCached: true },
                    url: new URL(request.url).pathname + new URL(request.url).search,
                    version: request.headers.get('X-Inertia-Version') || null,
                },
                {
                    headers: {
                        'X-Inertia': 'true',
                        'Content-Type': 'application/json',
                        'Cache-Control': 'no-store',
                        'X-KaBarangay-Offline': 'saved-copy',
                    },
                },
            );
        }
        if (partial) {
            // Keep already loaded props during a failed deferred/poll request.
            return Response.json(
                {
                    component: request.headers.get('X-Inertia-Partial-Component'),
                    props: {},
                    url: new URL(request.url).pathname + new URL(request.url).search,
                    version: request.headers.get('X-Inertia-Version') || null,
                },
                {
                    headers: { 'X-Inertia': 'true', 'Cache-Control': 'no-store' },
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
    if (request.method === 'GET' && url.pathname === '/build/manifest.json') {
        // The manifest drives shell precache/version checks; prefer fresh when online.
        event.respondWith(
            (async () => {
                try {
                    const response = await fetch(request, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                    });
                    if (
                        response.ok &&
                        (response.headers.get('Content-Type') || '').includes('json')
                    )
                        await (await caches.open(ASSETS)).put(request, response.clone());
                    return response;
                } catch {
                    return (
                        (await caches.match(request)) ||
                        (await (await caches.open(ASSETS)).match(request)) ||
                        Response.error()
                    );
                }
            })(),
        );
        return;
    }
    if (assetPath(url.href) && request.method === 'GET') {
        event.respondWith(
            (async () => {
                const cache = await caches.open(ASSETS);
                const cached = await cache.match(request);
                if (cached) return cached;
                const response = await fetch(request);
                if (cacheableAssetResponse(url.pathname, response))
                    await cache.put(request, response.clone());
                return response;
            })(),
        );
        return;
    }
    event.respondWith(
        (async () => {
            const requestEpoch = authEpoch;
            const authRequest = isAuthRequest(request);
            // The health check needs the actual response or network failure.
            const sessionProbe =
                request.method === 'GET' && url.pathname === '/offline/session';
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
                                  headers: { 'X-Inertia-Location': OFFLINE_LOGOUT },
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
                try {
                    await ordered(finishPendingLogout);
                } catch (error) {
                    if (authRequest) throw error;
                }
                const response = await fetch(request);
                if (
                    response.status === 503 &&
                    response.headers.get('X-KaBarangay-Offline') === 'unavailable'
                ) {
                    // The server answered but its account database did not. Treat it
                    // like a lost connection — this account's own saved copy, or the
                    // offline shell — while leaving the cached scope untouched. Any
                    // other status, including a plain 500, stays the real error.
                    // Authentication flows must receive the real server response.
                    if (!authRequest && !sessionProbe) {
                        return fallback(request, true);
                    }
                }
                if (response.status === 401 || response.status === 419) {
                    await ordered(async () => {
                        if (requestEpoch !== authEpoch) return;
                        authEpoch++;
                        await clearScopedCaches();
                        await writeMetadata({ scope: null, pendingLogout: null });
                    });
                } else if (response.ok && response.headers.has('X-KaBarangay-Offline-Scope')) {
                    const scope = response.headers.get('X-KaBarangay-Offline-Scope') || null;
                    await ordered(() =>
                        requestEpoch === authEpoch ? setScope(scope) : undefined,
                    );
                }
                const navigationPath =
                    request.method === 'GET' && request.mode === 'navigate'
                        ? pagePath(request.url)
                        : null;
                if (
                    !authRequest &&
                    navigationPath &&
                    response.ok &&
                    (response.headers.get('Content-Type') || '').includes('text/html') &&
                    response.headers.has('X-KaBarangay-Offline-Scope')
                ) {
                    const shellScope = response.headers.get('X-KaBarangay-Offline-Scope') || null;
                    const shellHtml = response.clone();
                    event.waitUntil(
                        ordered(async () => {
                            const state = await metadata();
                            if (!shellScope || state.scope !== shellScope || state.pendingLogout) return;
                            const html = await shellHtml.text().catch(() => null);
                            if (html) await saveShellPage(navigationPath, html);
                        }),
                    );
                }
                const path = request.method === 'GET' ? propsPath(request.url) : null;
                if (
                    !authRequest &&
                    path &&
                    response.ok &&
                    request.headers.get('X-Inertia') === 'true' &&
                    (response.headers.get('Content-Type') || '').includes('application/json')
                )
                    event.waitUntil(
                        response
                            .clone()
                            .json()
                            .then((body) =>
                                body && body.props ? saveProps(path, body) : undefined,
                            )
                            .catch(() => {}),
                    );
                return response;
            } catch (error) {
                if (authRequest || sessionProbe) throw error;
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
                        data.path === '/logout' ? '/logout' : '/resident/logout',
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
                            const item = await (await cache.match(key)).json().catch(() => null);
                            if (!item || !item.path) continue;
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
                if (!path || typeof data.html !== 'string' || data.html.length > 1500000) {
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
                for (const key of keys.slice(0, Math.max(0, keys.length - MAX_PAGES)))
                    await pages.delete(key);
                reply({ ok: true, savedAt });
            } catch {
                reply({ ok: false });
            }
        }),
    );
});
