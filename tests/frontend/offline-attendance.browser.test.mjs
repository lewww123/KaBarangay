import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createHash, randomBytes } from 'node:crypto';
const require = createRequire(import.meta.url);
const { chromium } = require(
    process.env.PLAYWRIGHT_MODULE ||
        'C:/Users/ACER/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright',
);
const names = [
    'service-worker.js',
    'offline-viewer.js',
    'offline.html',
    'offline-certificate-store.js',
    'offline-certificates.html',
    'offline-certificates.js',
    'offline-attendance.html',
    'offline-attendance-store.js',
    'offline-attendance.js',
];
const files = Object.fromEntries(
    await Promise.all(
        names.map(async (name) => [
            '/' + name,
            await readFile('public/' + name, 'utf8'),
        ]),
    ),
);
const scopeA = 'a'.repeat(64),
    scopeB = 'b'.repeat(64),
    ownerA = 'c'.repeat(64),
    ownerB = 'd'.repeat(64);
const secret = randomBytes(32).toString('base64');
const qr = 'opaque-resident-qr-token';
let scope = scopeA,
    sends = 0,
    accepted = 0,
    dropAfterAccept = true;
const receipts = new Map();
const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    res.setHeader('Cache-Control', 'no-store');
    if (files[path]) {
        res.setHeader(
            'Content-Type',
            path.endsWith('.html') ? 'text/html' : 'application/javascript',
        );
        res.end(files[path]);
        return;
    }
    res.setHeader('X-KaBarangay-Offline-Scope', scope);
    if (path === '/offline/session') {
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ scope, csrfToken: 'test-csrf' }));
        return;
    }
    if (path.endsWith('/sync')) {
        sends++;
        let text = '';
        for await (const chunk of req) text += chunk;
        const payload = JSON.parse(text);
        assert.equal(req.headers['x-csrf-token'], 'test-csrf');
        if (payload.offline_owner !== ownerA || scope !== scopeA) {
            res.writeHead(403, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ message: 'Wrong account' }));
            return;
        }
        const replayed = receipts.has(payload.client_request_id);
        if (!replayed) {
            accepted++;
            receipts.set(payload.client_request_id, payload);
        }
        if (dropAfterAccept) {
            dropAfterAccept = false;
            req.socket.destroy();
            return;
        }
        res.writeHead(replayed ? 200 : 201, {
            'Content-Type': 'application/json',
        });
        res.end(
            JSON.stringify({
                replayed,
                result: {
                    outcome: 'recorded',
                    message: 'Server confirmed attendance',
                },
            }),
        );
        return;
    }
    res.setHeader('Content-Type', 'text/html');
    res.end('<!doctype html><title>Fixture</title>Attendance fixture');
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
let browser;
const rpc = (page, data) =>
    page.evaluate(
        async (data) =>
            new Promise((resolve) => {
                const channel = new MessageChannel();
                channel.port1.onmessage = (event) => resolve(event.data);
                navigator.serviceWorker.controller.postMessage(data, [
                    channel.port2,
                ]);
            }),
        data,
    );
const contextData = (owner = ownerA, session = scopeA) => ({
    owner,
    scope: session,
    key: secret,
    expiresAt: Date.now() + 86400000,
    grant: 'opaque-server-grant',
    event: { id: 7, title: 'Program attendance', department: 'clerk' },
    residents: [
        { id: 1, name: 'Manual Resident', number: 'ONE-001', qrHash: null },
        {
            id: 2,
            name: 'QR Resident',
            number: 'ONE-002',
            qrHash: createHash('sha256').update(qr).digest('hex'),
        },
    ],
});
try {
    browser = await chromium.launch({
        executablePath:
            process.env.CHROME_PATH ||
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
        headless: true,
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(origin + '/clerk/announcements/7/attendance');
    await page.evaluate(async () => {
        await navigator.serviceWorker.register('/service-worker.js');
        await navigator.serviceWorker.ready;
    });
    await page.waitForFunction(() => navigator.serviceWorker.controller);
    await page.evaluate(() => fetch('/offline/session'));
    assert.equal(
        (
            await rpc(page, {
                type: 'KB_ATTENDANCE_ACTIVATE',
                context: contextData(),
            })
        ).ok,
        true,
    );
    await context.setOffline(true);
    await page.goto(origin + '/offline-attendance.html?event=7');
    await page.getByText('Manual attendance', { exact: true }).waitFor();
    await page.locator('#resident').selectOption('1');
    await page.locator('#manual').click();
    await page
        .locator('#entries')
        .getByText(/Manual Resident.*pending/)
        .waitFor();
    await page.locator('#qr').fill(origin + '/verify/resident/' + qr);
    await page.locator('#qr').press('Enter');
    await page
        .locator('#entries')
        .getByText(/QR Resident.*pending/)
        .waitFor();
    assert.equal(
        sends,
        0,
        'Offline entries must not pretend to have reached server',
    );
    await page.locator('#qr').fill(qr);
    await page.locator('#scan').click();
    await page
        .locator('#notice')
        .getByText(/already has a local attendance entry/)
        .waitFor();
    assert.equal(await page.locator('#entries li').count(), 2);
    await page.locator('#clear-results').click();
    assert.equal(
        await page.locator('#entries li').count(),
        2,
        'Pending entries must not be deleted by clearing finished results',
    );
    await page.locator('#qr').fill('invalid-qr');
    await page.locator('#scan').click();
    await page
        .locator('#notice')
        .getByText(/not in this prepared eligible roster/)
        .waitFor();
    const stored = await page.evaluate(
        async () =>
            new Promise((resolve) => {
                const open = indexedDB.open('kabarangay-offline-attendance-v1');
                open.onsuccess = () => {
                    const request = open.result
                        .transaction('entries')
                        .objectStore('entries')
                        .getAll();
                    request.onsuccess = () =>
                        resolve(
                            request.result.map((item) => ({
                                ...item,
                                encrypted: {
                                    iv: [...item.encrypted.iv],
                                    bytes: [
                                        ...new Uint8Array(item.encrypted.bytes),
                                    ],
                                },
                            })),
                        );
                };
            }),
    );
    assert.ok(
        !JSON.stringify(stored).includes('Manual Resident') &&
            !JSON.stringify(stored).includes(qr),
        'Roster/QR must not be stored in plaintext entry metadata',
    );
    await context.setOffline(false);
    await page.locator('#sync').click();
    await page.waitForTimeout(500);
    await page.locator('#sync').click();
    await page.waitForFunction(
        () =>
            [...document.querySelectorAll('#entries li')].length === 2 &&
            [...document.querySelectorAll('#entries li')].every((li) =>
                li.textContent.includes('synced'),
            ),
        null,
        { timeout: 20000 },
    );
    assert.equal(
        accepted,
        2,
        'A lost response must retry with the same request ID',
    );
    assert.ok(sends >= 3);
    scope = scopeB;
    await page.evaluate(() => fetch('/offline/session'));
    await page.waitForFunction(() =>
        document
            .querySelector('#notice')
            .textContent.includes('Account changed'),
    );
    assert.equal(
        await page.locator('#entries li').count(),
        0,
        'Open screens must clear old personal data immediately on account switch',
    );
    assert.equal(
        (
            await rpc(page, {
                type: 'KB_ATTENDANCE_ACTIVATE',
                context: contextData(ownerB, scopeB),
            })
        ).ok,
        true,
    );
    await page.reload();
    await page.waitForFunction(() => document.querySelector('#event option'));
    assert.equal(
        await page.locator('#entries li').count(),
        0,
        'Other account must not see the previous staff queue',
    );
    scope = scopeA;
    await page.evaluate(() => fetch('/offline/session'));
    assert.equal(
        (
            await rpc(page, {
                type: 'KB_ATTENDANCE_ACTIVATE',
                context: contextData(),
            })
        ).ok,
        true,
    );
    await page.reload();
    await page
        .locator('#entries li')
        .filter({ hasText: 'QR Resident' })
        .waitFor();
    await context.setOffline(true);
    await page.locator('#logout').click();
    await page.waitForURL('**/offline.html?loggedout=1');
    await page.goto(origin + '/offline-attendance.html');
    await page
        .locator('#notice')
        .getByText(/Sign in online and prepare/)
        .waitFor();
    assert.equal(await page.locator('#entries li').count(), 0);
    console.log(
        'PASS: actual offline manual/keyboard-QR entry, encrypted queue, invalid/duplicate QR, reconnect, uncertain response retry, account isolation, logout locking.',
    );
} finally {
    await browser?.close();
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
}
