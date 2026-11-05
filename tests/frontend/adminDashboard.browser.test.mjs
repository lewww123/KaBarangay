import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { createRequire } from 'node:module';
import { build } from 'vite-plus';
import react from '@vitejs/plugin-react';
const require = createRequire(import.meta.url);
const { chromium } = require('C:/Users/ACER/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = resolve('tests/frontend');
const dir = await mkdtemp(join(root, '.tmp-dashboard-'));
let bundle;
try {
    await writeFile(join(dir, 'entry.tsx'), `import React from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp} from '@inertiajs/react';import Dashboard from '/resources/js/pages/Dashboard';createInertiaApp({page:window.__PAGE__,resolve:()=>Dashboard,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});`);
    const result = await build({ configFile: false, root: resolve('.'), plugins: [react()], resolve: { alias: { '@': resolve('resources/js') } }, define: { 'process.env.NODE_ENV': '"production"' }, logLevel: 'error', build: { write: false, lib: { entry: join(dir, 'entry.tsx'), formats: ['es'] } } });
    bundle = (Array.isArray(result) ? result[0] : result).output.find(item => item.type === 'chunk' && item.isEntry).code;
} finally {
    if (!resolve(dir).startsWith(root + '\\') && !resolve(dir).startsWith(root + '/')) throw new Error('Invalid fixture path');
    await rm(dir, { recursive: true, force: true });
}
const announcement = { id: 42, title: 'Medical program', category: 'Program', content: 'Bring your resident ID.', eventStartAt: null, location: 'Sicsican' };
const notification = { id: 'reg-1', icon: '!', title: 'Registration to review', description: 'Test applicant', time: 'Today', href: '/admin/residents' };
const purok = { id: 1, name: 'Banaba' };
const shapes = { array: [announcement], keyed: { 42: announcement }, empty: [], null: null, invalid: 'unexpected value' };
const server = createServer((req, res) => {
    if (req.url === '/client.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(bundle); }
    const mode = new URL(req.url, 'http://localhost').searchParams.get('mode');
    const props = { errors: {}, stats: { residents: 11, puroks: 19, cases: 0, totalPrograms: 1, totalAnnouncements: 1 }, announcements: shapes[mode], notifications: mode === 'keyed' ? { 1: notification } : [notification], puroks: mode === 'keyed' ? { 1: purok } : [purok], activity: { activeStaff: 5 } };
    res.setHeader('Content-Type', 'text/html');
    res.end(`<div id="app"></div><script>window.__PAGE__=${JSON.stringify({ component: 'Dashboard', props, url: req.url, version: 'test' })}</script><script type="module" src="/client.js"></script>`);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const mode of Object.keys(shapes)) {
        await page.goto(`http://127.0.0.1:${server.address().port}/admin?mode=${mode}`);
        await page.getByRole('heading', { name: 'Admin Dashboard' }).waitFor();
        await page.getByText('Registration to review', { exact: true }).waitFor();
        if (['array', 'keyed'].includes(mode)) {
            await page.getByText('Medical program', { exact: true }).waitFor();
            await page.getByRole('button', { name: 'View details', exact: true }).click();
            await page.getByText('Bring your resident ID.', { exact: true }).waitFor();
        } else await page.getByText('No published announcements.', { exact: true }).waitFor();
        await page.getByRole('button', { name: /Puroks/ }).click();
        await page.getByText('Banaba', { exact: true }).waitFor();
    }
    assert.deepEqual(errors, []);
    console.log('PASS: Overview renders array, keyed-object, empty, null, and malformed announcement props; details, notifications, and purok lists work without page errors.');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
