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
const directory = await mkdtemp(join(root, '.tmp-staff-'));
if (
    !resolve(directory).startsWith(root + '\\') &&
    !resolve(directory).startsWith(root + '/')
)
    throw new Error('Invalid fixture path');

let bundle;
try {
    await writeFile(
        join(directory, 'camera.tsx'),
        `import React from 'react';export default function Camera({onScan}){return <button type="button" onClick={()=>onScan('TEST-QR')}>Simulate QR scan</button>}`,
    );
    await writeFile(
        join(directory, 'entry.tsx'),
        `import React from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp} from '@inertiajs/react';import Staff from '/resources/js/pages/Admin/Staff';createInertiaApp({page:window.__PAGE__,resolve:()=>Staff,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});`,
    );
    const result = await build({
        configFile: false,
        root: resolve('.'),
        plugins: [react()],
        resolve: {
            alias: [
                {
                    find: '@/components/lupon/QrCamera',
                    replacement: join(directory, 'camera.tsx'),
                },
                { find: '@', replacement: resolve('resources/js') },
            ],
        },
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
const resident = { id: 2, name: 'Maria Santos', resident_number: 'SJ-002' };
const assignments = [
    {
        id: 1,
        resident: { id: 1, name: 'Ana Clerk', resident_number: 'SJ-001' },
        user_email: 'ana@example.test',
        department: 'clerk',
        department_label: 'Records and Certificates',
        position: 'Barangay Clerk',
        is_active: true,
    },
];
const departments = [
    {
        value: 'clerk',
        label: 'Records and Certificates',
        position: 'Barangay Clerk',
    },
    { value: 'lupon', label: 'Lupon Tagapamayapa', position: 'Lupon Officer' },
];
let saved;
let searchFailure = false;
const initialPage = () => ({
    component: 'Admin/Staff',
    url: '/admin/staff',
    version: 'test',
    props: {
        errors: {},
        filters: { search: '' },
        departments,
        assignments: {
            data: assignments,
            total: assignments.length,
            from: 1,
            to: assignments.length,
            current_page: 1,
            last_page: 1,
            next_page_url: null,
            prev_page_url: null,
        },
    },
});
const server = createServer(async (req, res) => {
    if (req.url === '/client.js') {
        res.setHeader('Content-Type', 'text/javascript');
        return res.end(bundle);
    }
    if (req.url.startsWith('/api/qr/verify-token')) {
        res.setHeader('Content-Type', 'application/json');
        return res.end(JSON.stringify({ resident: { id: resident.id } }));
    }
    if (req.url.startsWith('/admin/staff/residents')) {
        res.setHeader('Content-Type', 'application/json');
        if (searchFailure) {
            res.statusCode = 503;
            return res.end('{}');
        }
        return res.end(JSON.stringify({ residents: [resident] }));
    }
    if (req.method === 'POST' && req.url === '/admin/staff') {
        let body = '';
        for await (const chunk of req) body += chunk;
        saved = JSON.parse(body);
        assignments.push({
            id: 2,
            resident,
            user_email: 'maria@example.test',
            department: saved.department,
            department_label: departments.find(
                (d) => d.value === saved.department,
            ).label,
            position: saved.position,
            is_active: true,
        });
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('X-Inertia', 'true');
        return res.end(JSON.stringify(initialPage()));
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(
        `<div id="app"></div><script>window.__PAGE__=${JSON.stringify(initialPage())}</script><script type="module" src="/client.js"></script>`,
    );
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({
    headless: true,
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/admin/staff`);
    await page.getByRole('heading', { name: 'Barangay Staff' }).waitFor();
    assert.equal(
        await page.getByRole('dialog').count(),
        0,
        'assignment form must initially be hidden',
    );
    await page
        .getByRole('button', { name: 'Assign new staff', exact: true })
        .click();
    await page.getByRole('dialog').waitFor();
    await page.getByLabel('Resident', { exact: true }).fill('Maria Santos');
    await page
        .getByLabel('Resident suggestions')
        .getByRole('button')
        .filter({ hasText: 'Maria Santos' })
        .click();
    await page.getByLabel('Department', { exact: true }).selectOption('clerk');
    assert.equal(
        await page.getByLabel('Position', { exact: true }).inputValue(),
        'Barangay Clerk',
    );
    await page
        .getByRole('button', { name: 'Assign staff', exact: true })
        .click();
    await page.getByRole('dialog').waitFor({ state: 'hidden' });
    assert.equal(String(saved.resident_id), '2');
    assert.equal(saved.department, 'clerk');
    await page
        .getByRole('cell')
        .filter({ hasText: 'maria@example.test' })
        .waitFor();
    await page
        .getByRole('button', { name: 'Assign new staff', exact: true })
        .click();
    assert.equal(
        await page.getByLabel('Resident', { exact: true }).inputValue(),
        '',
    );
    await page.getByRole('button', { name: 'Scan resident QR' }).click();
    await page.getByRole('button', { name: 'Simulate QR scan' }).click();
    await page.getByText('Selected: Maria Santos').waitFor();
    await page.keyboard.press('Escape');
    await page.getByRole('dialog').waitFor({ state: 'hidden' });
    await page
        .getByRole('button', { name: 'Edit', exact: true })
        .first()
        .click();
    assert.equal(
        await page.getByLabel('Resident', { exact: true }).isDisabled(),
        true,
    );
    assert.equal(
        await page.getByLabel('Position', { exact: true }).inputValue(),
        'Barangay Clerk',
    );
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    searchFailure = true;
    await page
        .getByRole('button', { name: 'Assign new staff', exact: true })
        .click();
    await page.getByLabel('Resident', { exact: true }).fill('Maria');
    await page
        .getByText('Unable to search residents. Please try again.')
        .waitFor();
    assert.deepEqual(errors, []);
    console.log(
        'Staff browser checks passed: modal, autocomplete, department/position, submit, QR selection, edit, Escape, and search errors.',
    );
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
