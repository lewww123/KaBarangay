import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { randomBytes } from 'node:crypto';
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
    'offline-attendance-store.js',
    'offline-attendance.html',
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
const keys = {
    [ownerA]: randomBytes(32).toString('base64'),
    [ownerB]: randomBytes(32).toString('base64'),
};
let scope = scopeA,
    owner = ownerA,
    revision = 'e'.repeat(64),
    fee = '50.00',
    dropAfterAccept = false,
    creations = 0;
const receipts = new Map();
const catalog = () => ({
    owner,
    key: keys[owner],
    scope,
    resident: {
        name: owner === ownerA ? 'Resident Alpha' : 'Resident Beta',
        barangay: 'Fixture Barangay',
    },
    preparedAt: new Date().toISOString(),
    certificates: [
        {
            id: '1',
            name: 'Certificate of Indigency',
            fee,
            description: 'Fixture',
            requirements: ['Valid ID'],
            fixedPurpose: null,
            requiresBusinessDetails: false,
            detailsFields: [],
            revision,
        },
    ],
});
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
    res.setHeader('X-KaBarangay-Offline-Scope', scope || '');
    const json = (data, status = 200) => {
        res.writeHead(status, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(data));
    };
    if (path === '/offline/session')
        return json({ scope, owner, csrfToken: 'fixture-csrf' });
    if (path === '/resident/certificates/offline-context')
        return json(catalog());
    if (path === '/resident/certificates/sync') {
        let body = '';
        for await (const chunk of req) body += chunk;
        const payload = JSON.parse(body);
        assert.equal(req.headers['x-csrf-token'], 'fixture-csrf');
        if (payload.offline_owner !== owner)
            return json({ message: 'Wrong resident account' }, 403);
        const key = owner + ':' + payload.client_request_id;
        if (receipts.has(key)) return json(receipts.get(key));
        if (payload.catalog_revision !== revision)
            return json(
                {
                    code: 'catalog_changed',
                    message: 'Fee changed; review current catalog.',
                },
                409,
            );
        creations++;
        const receipt = {
            reference: 'CERT-FIXTURE-' + creations,
            status: 'Pending',
            fee,
        };
        receipts.set(key, receipt);
        if (dropAfterAccept) {
            dropAfterAccept = false;
            req.socket.destroy();
            return;
        }
        return json(receipt, 201);
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(
        '<!doctype html><title>Fixture</title>Certificate fixture<script src="/offline-certificate-store.js"></script>',
    );
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
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
const activate = async (page) => {
    await page.evaluate(() => fetch('/offline/session'));
    const value = await page.evaluate(() =>
        fetch('/resident/certificates/offline-context').then((response) =>
            response.json(),
        ),
    );
    assert.equal(
        (await rpc(page, { type: 'KB_CERT_ACTIVATE', context: value })).ok,
        true,
    );
};
const localStatus = async (page, status) => {
    for (let i = 0; i < 150; i++) {
        const items = await page.evaluate(() => KBCertificates.list());
        if (items.some((item) => item.status === status)) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    assert.fail(
        'Expected local status ' +
            status +
            ': ' +
            JSON.stringify(await page.evaluate(() => KBCertificates.list())) +
            ' UI: ' +
            (await page.locator('#message').textContent()),
    );
};
let browser;
try {
    browser = await chromium.launch({
        executablePath:
            process.env.CHROME_PATH ||
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
        headless: true,
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    await page.goto(origin + '/resident/certificates');
    await page.evaluate(async () => {
        await navigator.serviceWorker.register('/service-worker.js');
        await navigator.serviceWorker.ready;
    });
    await page.waitForFunction(() => navigator.serviceWorker.controller);
    await activate(page);
    await context.setOffline(true);
    await page.goto(origin + '/offline-certificates.html');
    await page.locator('#unlocked').waitFor({ state: 'visible' });
    await page.locator('#certificate').selectOption('1');
    await page.locator('#purpose').fill('Private offline purpose');
    await page.locator('#save-draft').click();
    await localStatus(page, 'draft');
    await page.reload();
    await page.getByRole('button', { name: 'Edit draft', exact: true }).click();
    assert.equal(
        await page.locator('#purpose').inputValue(),
        'Private offline purpose',
    );
    await page
        .getByRole('button', { name: 'Review and queue request' })
        .click();
    await page.locator('#confirm').click();
    await localStatus(page, 'pending');
    assert.equal(creations, 0);
    assert.equal(await page.getByText(/Official reference:/).count(), 0);
    const firstId = await page.evaluate(
        async () => (await KBCertificates.list())[0].id,
    );
    await page.reload();
    await localStatus(page, 'pending');
    // An administrator changes the fee while this device is offline.
    revision = 'f'.repeat(64);
    fee = '75.00';
    await context.setOffline(false);
    await page.locator('#sync').click();
    await localStatus(page, 'needs-review');
    assert.equal(creations, 0);
    await page
        .getByRole('button', { name: 'Review with current catalog' })
        .click();
    await page.locator('#certificate-info').getByText(/75.00/).waitFor();
    await page
        .getByRole('button', { name: 'Review and queue request' })
        .click();
    await page.locator('#review-details').getByText(/75.00/).waitFor();
    await page.locator('#confirm').click();
    await localStatus(page, 'synced');
    assert.equal(creations, 1);
    assert.ok(receipts.has(ownerA + ':' + firstId));
    await page
        .getByText('Official reference: CERT-FIXTURE-1', { exact: true })
        .waitFor();
    // The server accepts a second request but its response is lost. Retry keeps its UUID.
    await context.setOffline(true);
    const secondId = await page.evaluate(async () => {
        const id = await KBCertificates.saveDraft({
            certificate_id: '1',
            purpose: 'Response loss fixture',
            details: {},
        });
        await KBCertificates.queue(id);
        return id;
    });
    dropAfterAccept = true;
    await context.setOffline(false);
    await page.evaluate(() => KBCertificates.sync());
    await page.evaluate(() =>
        Promise.all([KBCertificates.sync(), KBCertificates.sync()]),
    );
    await localStatus(page, 'synced');
    assert.equal(
        (await page.evaluate(() => KBCertificates.list())).find(
            (item) => item.id === secondId,
        )?.status,
        'synced',
    );
    assert.equal(creations, 2);
    assert.equal(receipts.size, 2);
    const raw = await page.evaluate(
        () =>
            new Promise((resolve) => {
                const request = indexedDB.open(
                    'kabarangay-offline-certificates-v1',
                );
                request.onsuccess = () => {
                    const tx = request.result.transaction('requests');
                    const read = tx.objectStore('requests').getAll();
                    read.onsuccess = () => resolve(JSON.stringify(read.result));
                };
            }),
    );
    assert.ok(!raw.includes('Private offline purpose'));
    assert.ok(!raw.includes('CERT-FIXTURE'));
    assert.ok(!raw.includes('Response loss fixture'));
    // A valid-looking local queue cannot submit after the server login expires.
    await context.setOffline(true);
    const expiredId = await page.evaluate(async () => {
        const id = await KBCertificates.saveDraft({
            certificate_id: '1',
            purpose: 'Expired login fixture',
            details: {},
        });
        await KBCertificates.queue(id);
        return id;
    });
    owner = null;
    await context.setOffline(false);
    await page.evaluate(() => KBCertificates.sync());
    await localStatus(page, 'login-required');
    assert.equal(creations, 2);
    owner = ownerA;
    await activate(page);
    await page.evaluate(() => KBCertificates.sync());
    assert.equal(
        (await page.evaluate(() => KBCertificates.list())).find(
            (item) => item.id === expiredId,
        )?.status,
        'synced',
    );
    assert.equal(creations, 3);
    // Switching accounts removes the key, rejects stale activation, and isolates drafts.
    const oldContext = await page.evaluate(() => KBCertificates.context());
    scope = scopeB;
    owner = ownerB;
    await page.evaluate(() => fetch('/offline/session'));
    assert.equal(
        (await rpc(page, { type: 'KB_CERT_ACTIVATE', context: oldContext })).ok,
        false,
    );
    await activate(page);
    assert.equal((await page.evaluate(() => KBCertificates.list())).length, 0);
    const thirdId = await page.evaluate(() =>
        KBCertificates.saveDraft({
            certificate_id: '1',
            purpose: 'Beta draft',
            details: {},
        }),
    );
    assert.ok(thirdId);
    scope = scopeA;
    owner = ownerA;
    await activate(page);
    assert.equal((await page.evaluate(() => KBCertificates.list())).length, 3);
    await rpc(page, { type: 'KB_LOGOUT', path: '/resident/logout' });
    const activeKey = await page.evaluate(
        () =>
            new Promise((resolve) => {
                const request = indexedDB.open(
                    'kabarangay-offline-certificates-v1',
                );
                request.onsuccess = () => {
                    const read = request.result
                        .transaction('meta')
                        .objectStore('meta')
                        .get('active');
                    read.onsuccess = () => resolve(read.result || null);
                };
            }),
    );
    assert.equal(activeKey, null);
    await page.waitForFunction(
        () =>
            document.querySelector('#locked') &&
            !document.querySelector('#locked').hidden,
    );
    assert.ok(!(await page.content()).includes('Private offline purpose'));
    console.log(
        'Passed: encrypted offline drafts, review, fee conflicts, idempotent retries, simultaneous sync, expired login, account isolation and logout lock.',
    );
} finally {
    await browser?.close();
    await new Promise((resolve) => server.close(resolve));
}
