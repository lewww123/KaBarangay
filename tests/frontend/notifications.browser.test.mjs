import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve, join } from 'node:path';
import { build } from 'vite-plus';
import react from '@vitejs/plugin-react';

const require = createRequire(import.meta.url);
const { chromium } = require(
    process.env.PLAYWRIGHT_MODULE ||
        'C:/Users/ACER/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright',
);
const fixtureRoot = resolve('tests/frontend');
const fixtureDir = await mkdtemp(join(fixtureRoot, '.tmp-notifications-'));
let bundle;
try {
    await writeFile(
        join(fixtureDir, 'entry.tsx'),
        `import React from 'react';import {createRoot} from 'react-dom/client';import NotificationBell from '/resources/js/components/resident/NotificationBell';createRoot(document.getElementById('root')).render(<NotificationBell/>);`,
    );
    const result = await build({
        configFile: false,
        root: resolve('.'),
        plugins: [react()],
        define: { 'process.env.NODE_ENV': '"production"' },
        logLevel: 'error',
        build: {
            write: false,
            lib: { entry: join(fixtureDir, 'entry.tsx'), formats: ['es'] },
        },
    });
    bundle = (Array.isArray(result) ? result[0] : result).output.find(
        (item) => item.type === 'chunk' && item.isEntry,
    ).code;
} finally {
    if (
        resolve(fixtureDir).startsWith(fixtureRoot + '\\') ||
        resolve(fixtureDir).startsWith(fixtureRoot + '/')
    )
        await rm(fixtureDir, { recursive: true, force: true });
}
let items = [
    {
        id: 1,
        title: 'Certificate ready for pickup',
        message: 'Your certificate is ready.',
        url: '/resident/certificates',
        read_at: null,
        created_at: new Date().toISOString(),
    },
];
let fail = false;
const server = createServer((req, res) => {
    if (req.url === '/client.js') {
        res.setHeader('Content-Type', 'text/javascript');
        return res.end(bundle);
    }
    if (req.url?.startsWith('/resident/notifications')) {
        res.setHeader('Content-Type', 'application/json');
        if (fail) {
            res.statusCode = 500;
            return res.end('{}');
        }
        if (req.method === 'POST') {
            items = items.map((item) => ({
                ...item,
                read_at: new Date().toISOString(),
            }));
            return res.end('{"ok":true}');
        }
        return res.end(
            JSON.stringify({
                items,
                unread_count: items.filter((item) => !item.read_at).length,
            }),
        );
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(
        '<!doctype html><html><body><header style="display:flex;justify-content:flex-end"><div id="root"></div></header><button id="outside">Outside</button><script type="module" src="/client.js"></script></body></html>',
    );
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
let browser;
try {
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
    });
    const page = await browser.newPage({
        viewport: { width: 390, height: 844 },
    });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    await page.getByRole('button', { name: 'Notifications, 1 unread' }).click();
    await page
        .getByRole('heading', { name: 'Notifications', exact: true })
        .waitFor();
    await page.getByRole('button', { name: 'Mark all as read' }).click();
    await page
        .getByRole('button', { name: 'Notifications, 0 unread' })
        .waitFor();
    await page.keyboard.press('Escape');
    assert.equal(
        await page
            .getByRole('heading', { name: 'Notifications', exact: true })
            .count(),
        0,
    );
    assert.equal(
        await page
            .getByRole('button', { name: 'Notifications, 0 unread' })
            .evaluate((el) => el === document.activeElement),
        true,
    );
    await page.getByRole('button', { name: 'Notifications, 0 unread' }).click();
    await page.locator('#outside').click();
    assert.equal(
        await page
            .getByRole('heading', { name: 'Notifications', exact: true })
            .count(),
        0,
    );
    items = [];
    await page.getByRole('button', { name: 'Notifications, 0 unread' }).click();
    await page.getByText('You’re all caught up.', { exact: false }).waitFor();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    fail = true;
    await page.getByRole('button', { name: 'Notifications, 0 unread' }).click();
    await page.getByRole('button', { name: 'Retry', exact: true }).waitFor();
    fail = false;
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await page.waitForFunction(
        () => !document.body.textContent.includes('Could not refresh'),
    );
    assert.deepEqual(errors, []);
    console.log(
        'PASS: unread count, dropdown, mark all read, Escape/focus return, outside click, empty state, failed request and retry.',
    );
} finally {
    await browser?.close();
    server.closeAllConnections();
    await new Promise((resolve) => server.close(resolve));
}
