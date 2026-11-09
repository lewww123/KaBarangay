import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdtemp, writeFile, rm } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve, join } from 'node:path';
import { build } from 'vite-plus';
import react from '@vitejs/plugin-react';
const require = createRequire(import.meta.url);
const { chromium } = require(
    process.env.PLAYWRIGHT_MODULE ||
        'C:/Users/ACER/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright',
);
const ts = require('typescript');
const snapshotSource = ts.transpileModule(
    await readFile('resources/js/lib/offlineSnapshot.ts', 'utf8'),
    {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ESNext,
        },
    },
).outputText;
const fixtureRoot = resolve('tests/frontend');
const fixtureDir = await mkdtemp(join(fixtureRoot, '.tmp-offline-'));
let clientBundle;
try {
    await writeFile(
        join(fixtureDir, 'entry.tsx'),
        `import React from 'react';import {createRoot} from 'react-dom/client';import OfflineSupport from '/resources/js/components/OfflineSupport';createRoot(document.getElementById('offline-test-support')).render(<OfflineSupport initialPage={{component:'Resident/Certificates',props:{errors:{},offlineScope:window.TEST_SCOPE},url:location.pathname+location.search,version:'test',clearHistory:false,encryptHistory:false}}/>);`,
    );
    const result = await build({
        configFile: false,
        root: resolve('.'),
        plugins: [react()],
        define: { 'process.env.NODE_ENV': '"production"' },
        resolve: { alias: { '@': resolve('resources/js') } },
        logLevel: 'error',
        build: {
            write: false,
            lib: { entry: join(fixtureDir, 'entry.tsx'), formats: ['es'] },
        },
    });
    const output = Array.isArray(result) ? result[0] : result;
    clientBundle = output.output.find(
        (item) => item.type === 'chunk' && item.isEntry,
    ).code;
} finally {
    if (
        resolve(fixtureDir).startsWith(fixtureRoot + '\\') ||
        resolve(fixtureDir).startsWith(fixtureRoot + '/')
    )
        await rm(fixtureDir, { recursive: true, force: true });
}
const files = Object.fromEntries(
    await Promise.all(
        [
            'service-worker.js',
            'offline-viewer.js',
            'offline.html',
            'offline-certificate-store.js',
            'offline-certificates.js',
            'offline-certificates.html',
            'offline-attendance.html',
            'offline-attendance-store.js',
            'offline-attendance.js',
        ].map(async (name) => [
            '/' + name,
            await readFile('public/' + name, 'utf8'),
        ]),
    ),
);
const scopeA = 'a'.repeat(64),
    scopeB = 'b'.repeat(64);
let scope = scopeA,
    writes = 0,
    logouts = 0;
let degraded = false;
let buggy = false;
// A signed-out /offline/session answer never reads the account tables, so it can only
// ever prove that this server is alive.
let guestProbe = false;
let legacyProbe = false;
let sessionProbes = 0;
let slowResponse;
const appScript = `import {offlineSnapshot} from '/snapshot.js';
window.appBooted=true;
window.rpc=async(message)=>{const registration=await navigator.serviceWorker.ready;return new Promise(resolve=>{const c=new MessageChannel();c.port1.onmessage=e=>resolve(e.data);(navigator.serviceWorker.controller||registration.active).postMessage(message,[c.port2])})};
window.prepare=async()=>{const here=location.pathname+location.search;for(let i=0;i<150;i++){const saved=await window.rpc({type:'KB_LIST'});if(saved?.pages?.some((page)=>page.path===here))return {ok:true};await new Promise(resolve=>setTimeout(resolve,100));}throw Error('Automatic offline preparation did not finish for '+here);};`;
const server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    res.setHeader('Cache-Control', 'no-store');
    if (files[url.pathname]) {
        res.setHeader(
            'Content-Type',
            url.pathname.endsWith('.html') ? 'text/html' : 'text/javascript',
        );
        res.end(files[url.pathname]);
        return;
    }
    if (url.pathname === '/client-ui.js') {
        res.setHeader('Content-Type', 'text/javascript');
        res.end(clientBundle);
        return;
    }
    if (url.pathname === '/snapshot.js' || url.pathname === '/app-test.js') {
        res.setHeader('Content-Type', 'text/javascript');
        res.end(url.pathname === '/snapshot.js' ? snapshotSource : appScript);
        return;
    }
    if (url.pathname === '/build/assets/offline-test.css') {
        res.setHeader('Content-Type', 'text/css');
        res.end(
            'body{font-family:system-ui;background:rgb(247,246,240)} #saved-record{color:rgb(49,91,48)}',
        );
        return;
    }
    // A degraded server says nothing about the scope: it neither issues one nor revokes one.
    if (!degraded) res.setHeader('X-KaBarangay-Offline-Scope', scope || '');
    if (url.pathname === '/test/degrade') {
        degraded = true;
        res.setHeader('Content-Type', 'application/json');
        res.end('{"degraded":true}');
        return;
    }
    if (url.pathname === '/test/recover') {
        degraded = false;
        res.setHeader('X-KaBarangay-Offline-Scope', scope || '');
        res.setHeader('Content-Type', 'application/json');
        res.end('{"recovered":true}');
        return;
    }
    // Stand in for a signed-out visitor: the real endpoint answers this shape without
    // reading the account tables, so it says the same thing during an outage or not.
    if (url.pathname === '/test/guest') {
        guestProbe = url.searchParams.get('off') !== '1';
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ guestProbe }));
        return;
    }
    if (url.pathname === '/test/slow-scope') {
        res.setHeader('Content-Type', 'application/json');
        slowResponse = res;
        return;
    }
    if (url.pathname === '/offline/session') {
        sessionProbes++;
        res.setHeader('Content-Type', 'application/json');
        res.end(
            JSON.stringify(
                guestProbe
                    ? {
                          scope: null,
                          owner: null,
                          identity: 'anonymous',
                          csrfToken: 'test-csrf-only',
                      }
                    : degraded
                      ? {
                            scope: null,
                            owner: null,
                            identity: 'unresolved',
                            csrfToken: 'test-csrf-only',
                        }
                      : {
                            scope,
                            identity: legacyProbe ? undefined : 'verified',
                            csrfToken: 'test-csrf-only',
                        },
            ),
        );
        return;
    }
    if (url.pathname === '/test/switch') {
        scope = scopeB;
        res.setHeader('X-KaBarangay-Offline-Scope', scope);
        res.setHeader('Content-Type', 'application/json');
        res.end('{}');
        return;
    }
    if (
        ['/logout', '/resident/logout'].includes(url.pathname) &&
        req.method === 'POST'
    ) {
        assert.equal(req.headers['x-csrf-token'], 'test-csrf-only');
        logouts++;
        scope = null;
        res.statusCode = 302;
        res.setHeader('Location', '/resident/login');
        res.end();
        return;
    }
    // The reported outage: Laravel is up and answers, but its database is not, so every
    // application route hands back the degraded contract instead of a silent success.
    if (url.pathname === '/test/bug') {
        buggy = true;
        res.setHeader('Content-Type', 'application/json');
        res.end('{"bug":true}');
        return;
    }
    if (url.pathname === '/test/bug-off') {
        buggy = false;
        res.setHeader('Content-Type', 'application/json');
        res.end('{"bug":false}');
        return;
    }
    // A real defect keeps its ordinary 500 shape: no marker, nothing to hand to the cache.
    if (buggy) {
        res.statusCode = 500;
        res.setHeader('Content-Type', 'text/html');
        res.end(
            '<!doctype html><html><head><title>Server error</title></head><body><h1>Genuine defect page</h1></body></html>',
        );
        return;
    }
    if (degraded) {
        res.statusCode = 503;
        res.setHeader('X-KaBarangay-Offline', 'unavailable');
        res.setHeader('Content-Type', 'text/html');
        res.end(
            '<!doctype html><html><head><title>Unavailable</title></head><body><h1>Outage page from the server</h1></body></html>',
        );
        return;
    }
    if (req.method === 'POST') {
        writes++;
        res.setHeader('Content-Type', 'application/json');
        res.end('{}');
        return;
    }
    if (url.pathname === '/resident/login') {
        res.setHeader('Content-Type', 'text/html');
        res.end('<h1>Sign in required</h1>');
        return;
    }
    if (url.pathname.startsWith('/resident/') && !scope) {
        res.statusCode = 302;
        res.setHeader('Location', '/resident/login');
        res.end();
        return;
    }
    if (url.pathname.startsWith('/resident/documents/')) {
        res.setHeader('Content-Type', 'application/pdf');
        res.end('private-file-must-never-be-saved');
        return;
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(
        `<!doctype html><html><head><meta name="csrf-token" content="secret-csrf"><meta name="viewport" content="width=device-width"><title>${url.pathname} · KaBarangay</title><link rel="stylesheet" href="/build/assets/offline-test.css"><script>window.TEST_SCOPE=${JSON.stringify(scope)}</script><script type="module" src="/app-test.js"></script><script type="module" src="/client-ui.js"></script><script data-page="app" type="application/json">{"privateBootstrap":"do-not-store"}</script></head><body><main id="app"><h1>Saved certificate list</h1><p id="saved-record">Ready for Claim</p><a href="/resident/other?tab=history">Other saved page</a><a href="/resident/missing">Unsaved page</a><a href="/resident/documents/1/download">Private download</a><form><input type="password" value="secret-password"><input value="unsaved-draft"><textarea>secret-notes</textarea><button>Submit</button></form><img src="/resident/documents/1" alt="Private image"><div role="dialog">temporary-account-password</div><div id="kabarangay-ai-chat">private-chat-copy</div><button onclick="window.bad=true">Unsafe action</button></main><div id="offline-test-support"></div></body></html>`,
    );
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
let browser;
const talk = (page, message) =>
    page.evaluate(async (message) => {
        const registration = await navigator.serviceWorker.ready;
        return new Promise((resolve) => {
            const channel = new MessageChannel();
            channel.port1.onmessage = (event) => resolve(event.data);
            (
                navigator.serviceWorker.controller || registration.active
            ).postMessage(message, [channel.port2]);
        });
    }, message);
// The probe timer is a quarter of a minute long, so wait on the server-side count instead
// of a blind sleep: it proves the client actually asked, and exactly how many times.
const waitForProbe = async (baseline) => {
    for (let attempt = 0; attempt < 200; attempt++) {
        if (sessionProbes > baseline) return sessionProbes;
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error('The connectivity probe never reached the server');
};
try {
    browser = await chromium.launch({
        executablePath:
            process.env.CHROME_PATH ||
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
        headless: true,
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.on('pageerror', (error) =>
        console.error('Browser error:', error.message),
    );
    await page.goto(origin + '/resident/certificates');
    await page.waitForFunction(() => typeof window.prepare === 'function');
    assert.equal((await page.evaluate(() => window.prepare())).ok, true);
    await page.goto(origin + '/resident/other?tab=history');
    await page.waitForFunction(() => typeof window.prepare === 'function');
    assert.equal((await page.evaluate(() => window.prepare())).ok, true);
    assert.equal((await talk(page, { type: 'KB_LIST' })).pages.length, 2);

    // An unreachable database is not a logout: a degraded response carries no scope, so
    // the copies saved under the real scope survive and no shared partition appears.
    await page.evaluate(() => fetch('/test/degrade'));
    await page.evaluate(() => fetch('/offline/session'));
    await page.evaluate(() => fetch('/resident/certificates'));

    // Laravel answered with an HTTP status, so a status-only probe would still call this
    // healthy. The identity in the body is what reports the outage instead.
    await page
        .getByText('Barangay data unavailable', { exact: true })
        .waitFor();
    assert.equal(
        await page.getByText('Offline Mode').count(),
        0,
        'A reachable server with an unreadable database is not "offline mode"',
    );

    // The existing write blocker follows the degraded state, not navigator.onLine.
    assert.equal(
        await page.evaluate(() => {
            const form = document.querySelector('form');
            window.submitRan = false;
            form.addEventListener('submit', () => (window.submitRan = true));
            form.requestSubmit();
            return window.submitRan;
        }),
        false,
        'A degraded server must not let a form submit',
    );

    // A signed-out probe answers "anonymous" without reading the account tables at all,
    // so that answer proves only that Laravel is alive. Read as proof that the data
    // service is readable it would re-open the write gate during the very outage that
    // queued the writes, so it must neither clear the card nor release the block.
    // A reconnect is what makes the client ask again; the probe timer runs a quarter of
    // a minute and a synthetic event is not what the browser delivers.
    const reconnectProbe = async () => {
        const baseline = sessionProbes;
        await context.setOffline(true);
        await context.setOffline(false);
        await waitForProbe(baseline);
    };
    await page.evaluate(() => fetch('/test/guest'));
    for (const round of ['first', 'second']) {
        await reconnectProbe();
        await page.evaluate(() => new Promise((done) => setTimeout(done, 250)));
        assert.equal(
            await page
                .getByText('Barangay data unavailable', { exact: true })
                .count(),
            1,
            `A ${round} anonymous probe must not be read as data recovery`,
        );
        assert.equal(
            await page.evaluate(() => {
                const form = document.querySelector('form');
                window.submitRan = false;
                form.addEventListener('submit', () => (window.submitRan = true));
                form.requestSubmit();
                return window.submitRan;
            }),
            false,
            `A ${round} anonymous probe must not re-open the write block`,
        );
    }
    assert.equal(await page.evaluate(() => navigator.onLine), true);
    assert.equal(
        (await talk(page, { type: 'KB_LIST' })).pages.length,
        2,
        'An anonymous probe must leave the saved partition alone',
    );

    // The same reconnect with an identity that had to read the account tables does end
    // the outage state, and the next answer that cannot read them brings it back: the
    // card follows the data service, never the network.
    await page.evaluate(() => fetch('/test/guest?off=1'));
    await page.evaluate(() => fetch('/test/recover'));
    await reconnectProbe();
    await page
        .getByText('Barangay data unavailable', { exact: true })
        .waitFor({ state: 'detached' });
    await page.evaluate(() => fetch('/test/degrade'));
    await reconnectProbe();
    await page
        .getByText('Barangay data unavailable', { exact: true })
        .waitFor();

    // A degraded navigation is answered from this account's own saved copy, and the
    // server's outage page never reaches the screen.
    await page.goto(origin + '/resident/certificates');
    await page.getByText('Saved certificate list', { exact: true }).waitFor();
    assert.equal(
        await page
            .getByText('Outage page from the server', { exact: true })
            .count(),
        0,
    );

    assert.equal(
        (await talk(page, { type: 'KB_LIST' })).pages.length,
        2,
        'Degraded responses must never clear a valid partition',
    );
    assert.ok(
        (await page.evaluate(() => caches.keys())).every((name) =>
            [
                '^kb-offline-v2-assets$',
                '^kb-offline-v2-meta$',
                '^kb-offline-v2-pages-[a-f0-9]{64}$',
                '^kb-offline-v2-shell-[a-f0-9]{64}$',
            ].some((pattern) => new RegExp(pattern).test(name)),
        ),
        'Degradation must not open a shared cache partition',
    );

    // Only the degraded contract is a hand-off. A plain 500 is a defect in this
    // application and must stay on screen instead of being hidden behind a saved copy.
    await page.evaluate(() => fetch('/test/recover'));
    await page.evaluate(() => fetch('/test/bug'));
    await page.goto(origin + '/resident/certificates');
    await page.getByText('Genuine defect page', { exact: true }).waitFor();
    assert.equal(
        await page.getByText('Saved certificate list', { exact: true }).count(),
        0,
    );
    await page.evaluate(() => fetch('/test/bug-off'));
    await page.goto(origin + '/resident/other?tab=history');
    await page.waitForFunction(() => typeof window.prepare === 'function');
    await page.evaluate(() => fetch('/test/recover'));
    assert.equal(
        (await talk(page, { type: 'KB_LIST' })).pages.length,
        2,
        'Recovery must keep the partition a blip left intact',
    );

    await context.setOffline(true);
    await page.getByText('Offline Mode', { exact: true }).waitFor();
    await page.goto(origin + '/resident/certificates');
    await page.getByText('Ready for Claim', { exact: true }).waitFor();
    assert.equal(await page.locator('#kb-offline-notice').count(), 1);
    assert.equal(await page.evaluate(() => window.appBooted), undefined);
    assert.equal(
        await page
            .locator('input,textarea,form,img,dialog,[role=dialog]')
            .count(),
        0,
    );
    assert.equal(await page.locator('meta[name=csrf-token]').count(), 0);
    const html = await page.content();
    for (const secret of [
        'secret-password',
        'unsaved-draft',
        'secret-notes',
        'privateBootstrap',
        'secret-csrf',
        'private-chat-copy',
        'temporary-account-password',
    ])
        assert.ok(!html.includes(secret), secret + ' leaked into offline copy');
    assert.equal(
        await page
            .locator('#saved-record')
            .evaluate((element) => getComputedStyle(element).color),
        'rgb(49, 91, 48)',
    );
    assert.equal(
        await page
            .getByText('Private download', { exact: true })
            .getAttribute('href'),
        null,
    );
    await page
        .getByRole('link', { name: 'Other saved page', exact: true })
        .click();
    assert.ok(page.url().includes('/resident/other?tab=history'));
    await page.locator('#kb-offline-notice').waitFor();
    await page.getByRole('link', { name: 'Unsaved page', exact: true }).click();
    await page.getByText('Hindi available offline ang page na ito', { exact: false }).waitFor();
    assert.ok(!page.url().includes('/offline.html'), 'Normal offline fallback must not navigate to standalone offline.html');
    assert.equal(
        (await talk(page, { type: 'KB_LIST' })).pages.length,
        2,
        'Saved pages must remain tracked for in-app/offline use',
    );
    assert.equal(
        await page.evaluate(
            async () =>
                (await fetch('/lupon/example-save', { method: 'POST' })).status,
        ),
        503,
    );
    const navigation = await page.evaluate(async () => {
        const response = await fetch('/resident/certificates', {
            headers: { 'X-Inertia': 'true' },
        });
        return {
            status: response.status,
            location: response.headers.get('X-Inertia-Location'),
        };
    });
    assert.equal(navigation.status, 409);
    assert.ok(navigation.location.endsWith('/resident/certificates'));
    const partial = await page.evaluate(async () =>
        (
            await fetch('/resident/certificates', {
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Partial-Component': 'Resident/Certificates',
                    'X-Inertia-Partial-Data': 'requests',
                },
            })
        ).json(),
    );
    assert.deepEqual(partial.props, {});
    assert.equal(partial.component, 'Resident/Certificates');
    assert.equal(writes, 0);

    await context.setOffline(false);
    await page.evaluate(() => {
        window.oldScopeRequest = fetch('/test/slow-scope');
    });
    for (let i = 0; !slowResponse && i < 200; i++)
        await new Promise((resolve) => setTimeout(resolve, 10));
    assert.ok(slowResponse);
    await page.evaluate(() => fetch('/test/switch'));
    slowResponse.end('{}');
    await page.evaluate(() =>
        window.oldScopeRequest.then((response) => response.json()),
    );
    assert.equal(
        (await talk(page, { type: 'KB_LIST' })).pages.length,
        0,
        'Account switch must clear the old copies',
    );
    assert.equal(
        (
            await talk(page, {
                type: 'KB_SAVE',
                scope: scopeA,
                path: '/resident/old',
                title: 'Old account',
                html: 'private old account',
                assets: [],
            })
        ).ok,
        false,
    );
    await page.goto(origin + '/resident/certificates');
    await page.waitForFunction(() => typeof window.prepare === 'function');
    await page.evaluate(() => window.prepare());
    await talk(page, {
        type: 'KB_SAVE',
        scope: scopeB,
        path: '/resident/expired',
        title: 'Expired record',
        html: '<h1>Expired private copy</h1>',
        assets: [],
    });
    await page.evaluate(async (scope) => {
        const cache = await caches.open('kb-offline-v2-pages-' + scope);
        for (const key of await cache.keys()) {
            const item = await (await cache.match(key)).json();
            if (item.path === '/resident/expired')
                await cache.put(
                    key,
                    Response.json({
                        ...item,
                        savedAt: Date.now() - 25 * 60 * 60 * 1000,
                    }),
                );
        }
    }, scopeB);
    await context.setOffline(true);
    await page.goto(origin + '/resident/expired');
    await page.getByText('Hindi available offline ang page na ito', { exact: false }).waitFor();
    assert.ok(!page.url().includes('/offline.html'), 'Expired uncached fallback must not use standalone offline.html');
    assert.equal(
        await page.getByText('Expired private copy', { exact: true }).count(),
        0,
    );
    assert.ok(
        !(await talk(page, { type: 'KB_LIST' })).pages.some(
            (item) => item.path === '/resident/expired',
        ),
    );
    await context.setOffline(false);
    for (let i = 0; i < 22; i++)
        await talk(page, {
            type: 'KB_SAVE',
            scope: scopeB,
            path: '/resident/saved-' + i,
            title: 'Saved page ' + i,
            html: '<h1>Read-only test page</h1>',
            assets: [],
        });
    const bounded = (await talk(page, { type: 'KB_LIST' })).pages;
    assert.equal(bounded.length, 20);
    assert.ok(!bounded.some((item) => item.path === '/resident/saved-0'));
    await page.goto(origin + '/resident/certificates');
    await page.waitForFunction(() => typeof window.prepare === 'function');
    await page.evaluate(() => window.prepare());
    await context.setOffline(true);
    await page.reload();
    await page.locator('#kb-offline-notice').waitFor();
    const otherSnapshot = await context.newPage();
    await otherSnapshot.goto(origin + '/resident/certificates');
    await otherSnapshot.locator('#kb-offline-notice').waitFor();
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await otherSnapshot.waitForURL('**/offline.html?loggedout=1');
    assert.equal(await otherSnapshot.locator('#kb-offline-notice').count(), 0);
    await otherSnapshot.close();
    await page.waitForURL('**/offline.html?loggedout=1');
    await page
        .getByRole('heading', { name: 'Mag-sign in ulit sa KaBarangay' })
        .waitFor();
    assert.equal(
        await page.getByRole('heading', { name: 'Mga page sa device na ito' }).count(),
        0,
    );
    assert.equal((await talk(page, { type: 'KB_LIST' })).pages.length, 0);
    assert.equal(
        logouts,
        0,
        'Offline logout must not claim the server already received it',
    );
    await context.setOffline(false);
    await page.goto(origin + '/resident/certificates');
    await page.getByRole('heading', { name: 'Sign in required' }).waitFor();
    assert.equal(
        logouts,
        1,
        'Pending logout must reach the server before any protected page resumes',
    );
    assert.equal(
        writes,
        0,
        'Business writes must never be replayed on reconnect',
    );
    assert.equal((await talk(page, { type: 'KB_LIST' })).pages.length, 0);

    scope = scopeA;
    await page.goto(origin + '/resident/certificates');
    await page.waitForFunction(() => typeof window.prepare === 'function');
    await page.evaluate(() => window.prepare());
    // A stale worker hint must not mark a reachable server offline, including
    // deployments that still return the original scope-only session response.
    legacyProbe = true;
    const confirmation = page.waitForResponse((response) =>
        response.url().endsWith('/offline/session'),
    );
    await page.evaluate(() => {
        navigator.serviceWorker.dispatchEvent(
            new MessageEvent('message', { data: { type: 'KB_NETWORK_OFFLINE' } }),
        );
    });
    await (await confirmation).finished();
    await page.getByText('Offline Mode', { exact: true }).waitFor({ state: 'hidden' });
    await page.getByText('Barangay data unavailable', { exact: true }).waitFor({ state: 'hidden' });
    const logoutDestination = await page.evaluate(async () => {
        const url = new URL('/resident/logout', location.origin);
        const allowed = document.dispatchEvent(
            new CustomEvent('inertia:before', {
                cancelable: true,
                detail: { visit: { method: 'post', url } },
            }),
        );
        if (!allowed) return null;
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': 'test-csrf-only', 'X-Inertia': 'true' },
        });
        return response.url;
    });
    assert.equal(logoutDestination, origin + '/resident/login');
    assert.deepEqual(await talk(page, { type: 'KB_LIST' }), {
        ok: true,
        pages: [],
        pendingLogout: false,
    });
    assert.equal(logouts, 2, 'Online logout must send only one request');
    console.log(
        'PASS: automatic saving, offline reload/navigation, styled read-only snapshots, secret/form/document exclusions, fallback, no write queue, account isolation, 24-hour expiry, 20-page limit, offline logout and server logout on reconnect.',
    );
} finally {
    await browser?.close();
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
}
