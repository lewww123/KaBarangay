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
let slowResponse;
const appScript = `import {offlineSnapshot} from '/snapshot.js';
window.appBooted=true;
window.rpc=async(message)=>{const registration=await navigator.serviceWorker.ready;return new Promise(resolve=>{const c=new MessageChannel();c.port1.onmessage=e=>resolve(e.data);(navigator.serviceWorker.controller||registration.active).postMessage(message,[c.port2])})};
window.prepare=async()=>{for(let i=0;i<150;i++){if(document.querySelector('[data-kb-offline-control]')?.textContent.includes('Saved for offline'))return {ok:true};await new Promise(resolve=>setTimeout(resolve,100));}throw Error('Automatic offline preparation did not finish: '+document.querySelector('[data-kb-offline-control]')?.textContent)};`;
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
    res.setHeader('X-KaBarangay-Offline-Scope', scope || '');
    if (url.pathname === '/test/slow-scope') {
        res.setHeader('Content-Type', 'application/json');
        slowResponse = res;
        return;
    }
    if (url.pathname === '/offline/session') {
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ scope, csrfToken: 'test-csrf-only' }));
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

    await context.setOffline(true);
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
    await page
        .getByRole('heading', { name: 'Mga page sa device na ito' })
        .waitFor();
    await page.waitForFunction(
        () => document.querySelectorAll('#kb-offline-pages li').length === 2,
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
        const cache = await caches.open('kb-offline-v1-pages-' + scope);
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
    await page
        .getByRole('heading', { name: 'Mga page sa device na ito' })
        .waitFor();
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
    await page.waitForFunction(() =>
        document
            .querySelector('#kb-offline-message')
            .textContent.includes('Na-clear'),
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
    console.log(
        'PASS: automatic saving, offline reload/navigation, styled read-only snapshots, secret/form/document exclusions, fallback, no write queue, account isolation, 24-hour expiry, 20-page limit, offline logout and server logout on reconnect.',
    );
} finally {
    await browser?.close();
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
}
