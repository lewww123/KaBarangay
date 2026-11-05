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
const directory = await mkdtemp(join(root, '.tmp-registration-'));
if (
    !resolve(directory).startsWith(root + '\\') &&
    !resolve(directory).startsWith(root + '/')
)
    throw new Error('Invalid fixture path');

let bundle;
try {
    await writeFile(
        join(directory, 'entry.tsx'),
        `import React from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp} from '@inertiajs/react';import Register from '/resources/js/pages/Resident/Register';import Pending from '/resources/js/pages/Resident/RegistrationPending';createInertiaApp({page:window.__PAGE__,resolve:name=>name==='Resident/Register'?Register:Pending,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});`,
    );
    const result = await build({
        configFile: false,
        root: resolve('.'),
        plugins: [react()],
        resolve: { alias: { '@': resolve('resources/js') } },
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
const barangays = ['San Jose', 'Sicsican', 'Sta. Monica'].map(
    (name, index) => ({
        id: index + 1,
        name,
        code: String(index + 1),
        city_municipality: 'Puerto Princesa',
        province: 'Palawan',
        puroks: [{ id: index + 1, name: 'Purok One', code: 'ONE' }],
    }),
);
const props = {
    errors: {},
    barangays,
    sectors: [
        {
            id: 1,
            name: 'Regular Resident',
            slug: 'regular',
            requires_proof: false,
        },
    ],
};
const pagePayload = (extra = {}) => ({
    component: 'Resident/Register',
    url: '/resident/register',
    version: 'test',
    props,
    ...extra,
});
let attempts = 0;
let submissions = [];
const server = createServer(async (req, res) => {
    if (req.url === '/client.js') {
        res.setHeader('Content-Type', 'text/javascript');
        return res.end(bundle);
    }
    if (req.method === 'POST' && req.url === '/resident/register') {
        let body = '';
        for await (const chunk of req) body += chunk;
        submissions.push(body);
        attempts++;
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('X-Inertia', 'true');
        if (attempts % 2 === 1) {
            // Reproduce a validation redirect that defers reference props again.
            return res.end(
                JSON.stringify(
                    pagePayload({
                        props: {
                            errors: {
                                registration:
                                    'The submission could not be saved. Please retry.',
                            },
                        },
                        deferredProps: { default: ['barangays', 'sectors'] },
                    }),
                ),
            );
        }
        return res.end(
            JSON.stringify({
                component: 'Resident/RegistrationPending',
                url: '/resident/register/submitted',
                version: 'test',
                props: { errors: {} },
            }),
        );
    }
    if (req.headers['x-inertia']) {
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('X-Inertia', 'true');
        return setTimeout(
            () =>
                res.end(
                    JSON.stringify(
                        pagePayload({
                            props: { barangays, sectors: props.sectors },
                        }),
                    ),
                ),
            100,
        );
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(
        `<div id="app"></div><script>window.__PAGE__=${JSON.stringify(pagePayload())}</script><script type="module" src="/client.js"></script>`,
    );
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const browser = await chromium.launch({
    headless: true,
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
    args: [
        '--use-fake-device-for-media-stream',
        '--use-fake-ui-for-media-stream',
    ],
});
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    for (let index = 0; index < barangays.length; index++) {
        await page.goto(
            `http://127.0.0.1:${server.address().port}/resident/register`,
        );
        await page
            .getByLabel('Barangay', { exact: false })
            .selectOption(String(index + 1));
        await page
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await page
            .getByLabel('First Name', { exact: false })
            .fill('Applicant' + index);
        await page.getByLabel('Last Name', { exact: false }).fill('Example');
        await page.getByLabel('Birth year', { exact: true }).selectOption('1990');
        await page.getByLabel('Birth month', { exact: true }).selectOption('01');
        await page.getByLabel('Birth day', { exact: true }).selectOption('01');
        await page.getByLabel('Sex', { exact: false }).selectOption('Female');
        await page
            .getByLabel('Civil Status', { exact: false })
            .selectOption('Single');
        await page
            .getByLabel('Purok', { exact: false })
            .selectOption(String(index + 1));
        await page
            .getByLabel('House No. / Street / Sitio', { exact: false })
            .fill('Test Street');
        await page.reload();
        await page.getByLabel('First Name', { exact: false }).waitFor();
        assert.equal(await page.getByLabel('First Name', { exact: false }).inputValue(), 'Applicant' + index);
        assert.equal(await page.getByLabel('Birth year', { exact: true }).inputValue(), '1990');
        assert.equal(await page.getByLabel('Birth month', { exact: true }).inputValue(), '01');
        assert.equal(await page.getByLabel('Birth day', { exact: true }).inputValue(), '01');
        assert.equal(await page.getByLabel('House No. / Street / Sitio', { exact: false }).inputValue(), 'Test Street');
        assert.equal(await page.getByLabel('Purok', { exact: false }).inputValue(), String(index + 1));
        await page
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await page.getByRole('button', { name: /Regular Resident/ }).click();
        await page
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await page
            .locator('input[type="file"]')
            .first()
            .setInputFiles({
                name: 'valid.pdf',
                mimeType: 'application/pdf',
                buffer: Buffer.from('%PDF-test-document'),
            });
        await page
            .getByRole('button', { name: 'Open Camera', exact: true })
            .click();
        await page
            .getByRole('button', { name: 'Take Photo', exact: true })
            .click();
        await page
            .getByText('Live identity photo captured', { exact: true })
            .waitFor();
        await page
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await page
            .getByLabel('Mobile Number', { exact: false })
            .fill('0998000070' + index);
        await page
            .getByLabel('Email address', { exact: false })
            .fill(index === 0 ? '' : 'applicant' + index + '@example.test');
        await page.getByLabel(/^Password/).fill('ResidentPassword123');
        await page
            .getByLabel('Confirm Password', { exact: false })
            .fill('ResidentPassword123');
        await page.reload();
        await page.getByLabel(/^Password/).waitFor();
        assert.equal(await page.getByLabel('Mobile Number', { exact: false }).inputValue(), '0998000070' + index);
        assert.equal(await page.getByLabel('Email address', { exact: false }).inputValue(), index === 0 ? '' : 'applicant' + index + '@example.test');
        assert.equal(await page.getByLabel(/^Password/).inputValue(), '');
        const stored = await page.evaluate(() => localStorage.getItem('kabarangay.resident-registration.v1'));
        assert.ok(!stored.includes('ResidentPassword123'));
        assert.ok(!stored.includes('email_verified'));
        await page.getByLabel(/^Password/).fill('ResidentPassword123');
        await page.getByLabel('Confirm Password', { exact: false }).fill('ResidentPassword123');
        await page
            .getByRole('button', { name: 'Continue', exact: true })
            .click();
        await page.getByRole('checkbox').check();
        const submitted = page.waitForResponse(
            (response) => response.request().method() === 'POST',
        );
        await page
            .getByRole('button', { name: 'Submit Registration', exact: true })
            .click();
        await submitted;
        await page.waitForTimeout(400);
        assert.ok(
            await page
                .getByText('Step 6 of 6', { exact: true })
                .first()
                .isVisible(),
            'a failed submission must keep the review step instead of resetting to Step 1',
        );
        await page
            .getByRole('alert')
            .filter({ hasText: 'The submission could not be saved.' })
            .first()
            .waitFor();
        await page
            .getByRole('button', { name: 'Submit Registration', exact: true })
            .click();
        await page
            .getByRole('heading', {
                name: 'Account is waiting for admin approval',
            })
            .waitFor();
        assert.equal(await page.evaluate(() => localStorage.getItem('kabarangay.resident-registration.v1')), null);
        for (const body of submissions.slice(-2)) {
            assert.ok(body.includes('Applicant' + index));
            assert.ok(body.includes('valid.pdf'));
            assert.ok(body.includes('live_identity_photo'));
        }
    }
    assert.equal(attempts, 6);
    assert.deepEqual(errors, []);
    console.log(
        'Registration browser checks passed for all three barangays: reload restores details and files without passwords, failed submit preserves draft, successful retry clears draft and reaches pending approval.',
    );
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
