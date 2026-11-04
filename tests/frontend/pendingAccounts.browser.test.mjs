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
const root = resolve('tests/frontend');
const directory = await mkdtemp(join(root, '.tmp-pending-'));
if (
    !resolve(directory).startsWith(root + '\\') &&
    !resolve(directory).startsWith(root + '/')
)
    throw new Error('Invalid fixture path');

let bundle;
try {
    await writeFile(
        join(directory, 'entry.tsx'),
        `
        import React,{useState} from 'react';
        import {createRoot} from 'react-dom/client';
        import useLivePendingAccounts from '/resources/js/hooks/useLivePendingAccounts';
        const initial={data:[]};
        function App(){
            const [query,setQuery]=useState('');
            const queue=useLivePendingAccounts(initial,query);
            return <><div id="items">{JSON.stringify(queue.data)}</div><div id="error">{queue.error}</div>
            <button id="refresh" onClick={()=>queue.refresh()}>Refresh</button>
            <button id="filter" onClick={()=>setQuery('registration_search=Other')}>Filter</button></>;
        }
        createRoot(document.getElementById('root')).render(<App/>);
    `,
    );
    const result = await build({
        configFile: false,
        root: resolve('.'),
        plugins: [react()],
        define: { 'process.env.NODE_ENV': '"production"' },
        logLevel: 'error',
        build: {
            write: false,
            lib: { entry: join(directory, 'entry.tsx'), formats: ['es'] },
        },
    });
    bundle = (Array.isArray(result) ? result[0] : result).output.find(
        (item) => item.type === 'chunk' && item.isEntry,
    ).code;
} finally {
    await rm(directory, { recursive: true, force: true });
}

let items = [];
let status = 200;
let requests = 0;
const server = createServer((req, res) => {
    if (req.url === '/client.js') {
        res.setHeader('Content-Type', 'text/javascript');
        return res.end(bundle);
    }
    if (req.url.startsWith('/admin/resident-registrations/pending?')) {
        requests++;
        res.setHeader('Content-Type', 'application/json');
        res.statusCode = status;
        if (status !== 200) return res.end('{}');
        const filtered = req.url.includes('registration_search=Other')
            ? items.filter((item) => item.name === 'Other')
            : items;
        return res.end(
            JSON.stringify({
                pendingRegistrations: { data: filtered },
                totalPending: items.length,
                updatedAt: new Date().toISOString(),
            }),
        );
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(
        '<div id="root"></div><script type="module" src="/client.js"></script>',
    );
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({
    headless: true,
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
try {
    const page = await browser.newPage();
    await page.addInitScript(() => {
        const interval = window.setInterval.bind(window);
        window.setInterval = (callback, delay, ...args) =>
            interval(callback, delay === 5000 ? 150 : delay, ...args);
    });
    await page.goto(`http://127.0.0.1:${server.address().port}`);
    await page.waitForFunction(
        () => document.querySelector('#items')?.textContent === '{"data":[]}',
    );
    items = [{ id: 1, name: 'New applicant' }];
    await page.waitForFunction(() =>
        document.querySelector('#items').textContent.includes('New applicant'),
    );
    assert.ok(
        requests >= 2,
        'new applicants must appear through polling, without page navigation',
    );
    status = 503;
    await page.waitForFunction(() =>
        document
            .querySelector('#error')
            .textContent.includes('Unable to refresh'),
    );
    assert.ok(
        (await page.locator('#items').textContent()).includes('New applicant'),
        'failed refresh must preserve the last list',
    );
    status = 200;
    items = [{ id: 2, name: 'Other' }];
    await page.locator('#refresh').click();
    await page.waitForFunction(
        () =>
            document.querySelector('#items').textContent.includes('Other') &&
            !document.querySelector('#error').textContent,
    );
    await page.locator('#filter').click();
    await page.waitForFunction(() =>
        document.querySelector('#items').textContent.includes('Other'),
    );
    status = 401;
    await page.waitForFunction(() =>
        document
            .querySelector('#error')
            .textContent.includes('session has expired'),
    );
    status = 200;
    items = [];
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForFunction(
        () =>
            document.querySelector('#items').textContent === '{"data":[]}' &&
            !document.querySelector('#error').textContent,
    );
    console.log(
        'Pending accounts browser checks passed: live updates, recovery, filters, expired session, and focus refresh.',
    );
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
