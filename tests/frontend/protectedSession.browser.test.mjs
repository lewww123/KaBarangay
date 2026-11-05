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
const dir = await mkdtemp(join(root, '.tmp-session-'));
let bundle;
try {
    await writeFile(join(dir, 'entry.tsx'), `import React,{useEffect} from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp,Link,router,usePage} from '@inertiajs/react';import {protectSession} from '/resources/js/lib/protectedSession';
function Screen(){const page=usePage();useEffect(()=>protectSession(page),[]);return <><h1>{page.props.secret||'Login'}</h1><Link href="/admin/second">Second page</Link><button onClick={()=>router.post('/logout')}>Logout</button></>};createInertiaApp({page:window.__PAGE__,resolve:()=>Screen,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});`);
    const result = await build({ configFile: false, root: resolve('.'), plugins: [react()], resolve: { alias: { '@': resolve('resources/js') } }, define: { 'process.env.NODE_ENV': '"production"' }, logLevel: 'error', build: { write: false, lib: { entry: join(dir, 'entry.tsx'), formats: ['es'] } } });
    bundle = (Array.isArray(result) ? result[0] : result).output.find(item => item.type === 'chunk' && item.isEntry).code;
} finally {
    if (!resolve(dir).startsWith(root + '\\') && !resolve(dir).startsWith(root + '/')) throw new Error('Invalid fixture path');
    await rm(dir, { recursive: true, force: true });
}
let account = 'Account A';
let logoutCount = 0;
let slowSession = false;
const server = createServer((req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    if (path === '/offline/session') {
        res.setHeader('Content-Type', 'application/json');
        if (slowSession) return setTimeout(() => res.end(JSON.stringify({scope:account})), 10000);
        return res.end(JSON.stringify({scope:account}));
    }
    if (path === '/client.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(bundle); }
    if (path === '/logout') {
        logoutCount++; account = null;
        res.writeHead(303, { Location: '/staff/login' }); return res.end();
    }
    if (path.startsWith('/admin') && !account) { res.writeHead(302, { Location: '/staff/login' }); return res.end(); }
    if (path === '/staff/login' && account) { res.writeHead(302, { Location: '/admin' }); return res.end(); }
    const page = { component: account ? 'Admin/Dashboard' : 'Auth/Login', props: { errors: {}, offlineScope: account, secret: account }, url: path, version: 'test', encryptHistory: true, clearHistory: !account };
    res.setHeader('Cache-Control', 'private, no-store');
    if (req.headers['x-inertia']) { res.setHeader('X-Inertia', 'true'); res.setHeader('Content-Type', 'application/json'); return res.end(JSON.stringify(page)); }
    res.setHeader('Content-Type', 'text/html'); res.end(`<div id="app"></div><script>window.__PAGE__=${JSON.stringify(page)}</script><script type="module" src="/client.js"></script>`);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const other = await context.newPage();
    const errors = [];
    for (const tab of [page, other]) tab.on('pageerror', error => errors.push(error.message));
    await page.goto(origin + '/admin');
    await page.getByRole('heading', { name: 'Account A' }).waitFor();
    slowSession = true;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.waitForTimeout(200);
    assert.equal(await page.getByRole('heading', { name: 'Account A' }).isVisible(), true, 'A slow focus check must not blank the page');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await page.waitForTimeout(8500);
    assert.equal(await page.getByRole('heading', { name: 'Account A' }).isVisible(), true, 'A stalled restore check must release its cover after the timeout');
    slowSession = false;
    assert.equal(await page.evaluate(() => JSON.stringify(history.state).includes('Account A')), false);
    await page.getByRole('link', { name: 'Second page' }).click();
    await page.waitForURL('**/admin/second');
    await page.goBack();
    await page.getByRole('heading', { name: 'Account A' }).waitFor();
    assert.equal(logoutCount, 0, 'Back must not log out');
    await other.goto(origin + '/admin');
    await other.getByRole('heading', { name: 'Account A' }).waitFor();
    await page.getByRole('button', { name: 'Logout' }).click();
    await page.getByRole('heading', { name: 'Login' }).waitFor();
    await other.getByRole('heading', { name: 'Login' }).waitFor();
    await page.goBack();
    await page.getByRole('heading', { name: 'Login' }).waitFor();
    assert.equal(await page.getByText('Account A', { exact: true }).count(), 0);
    await page.goForward();
    await page.getByRole('heading', { name: 'Login' }).waitFor();
    account = 'Account B';
    await page.goto(origin + '/admin');
    await page.getByRole('heading', { name: 'Account B' }).waitFor();
    await page.goBack();
    await page.waitForTimeout(250);
    assert.equal(await page.getByText('Account A', { exact: true }).count(), 0);
    await page.goto(origin + '/admin');
    await page.getByRole('heading', { name: 'Account B' }).waitFor();
    account = null;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByRole('heading', { name: 'Login' }).waitFor();
    assert.equal(logoutCount, 1, 'Session revalidation must not send a logout request');
    assert.deepEqual(errors, []);
    console.log('PASS: encrypted history, normal Back without logout, cross-tab logout, Back/Forward after logout, and account switching.');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
