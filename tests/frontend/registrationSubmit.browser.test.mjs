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
        `import React from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp,router} from '@inertiajs/react';import Register from '/resources/js/pages/Resident/Register';import Pending from '/resources/js/pages/Resident/RegistrationPending';import Application from '/resources/js/pages/Resident/RegistrationApplication';window.visitRegistrationFixture=url=>router.visit(url);createInertiaApp({page:window.__PAGE__,resolve:name=>({'Resident/Register':Register,'Resident/RegistrationApplication':Application,'Resident/RegistrationPending':Pending})[name],setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});`,
    );
    const result = await build({
        configFile: false,
        cacheDir: join(directory, 'vite-cache'),
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
const applicationUrl = '/resident/application/example';
const applicationPage = {
    component: 'Resident/RegistrationApplication',
    url: applicationUrl,
    version: 'test',
    props: {
        errors: {},
        application: {
            reference: 'example',
            applicant_name: 'Example Applicant',
            status: 'needs_correction',
            submitted_at: null,
            barangay: 'San Jose',
            purok: 'Purok One',
            correction: {
                cycle_number: 1,
                instructions: 'Replace both requested documents.',
                field_targets: [],
                document_targets: [
                    { key: 'valid_id', label: 'Valid ID' },
                    { key: 'senior_proof', label: 'Senior supporting document' },
                ],
            },
        },
    },
};
const pendingPage = {
    component: 'Resident/RegistrationPending',
    url: '/resident/register/submitted',
    version: 'test',
    props: { errors: {} },
};
let correctionSubmission;
let attempts = 0;
const submissions = [];
const server = createServer(async (req, res) => {
    if (req.url === '/client.js') {
        res.setHeader('Content-Type', 'text/javascript');
        return res.end(bundle);
    }
    if (req.method === 'POST' && req.url === applicationUrl + '/correction') {
        let body = '';
        for await (const chunk of req) body += chunk;
        correctionSubmission = { body, contentType: req.headers['content-type'] };
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('X-Inertia', 'true');
        return res.end(JSON.stringify({
            ...applicationPage,
            props: {
                ...applicationPage.props,
                application: {
                    ...applicationPage.props.application,
                    status: 'pending',
                    correction: null,
                },
            },
        }));
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
    if (req.url === pendingPage.url && req.headers['x-inertia']) {
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('X-Inertia', 'true');
        return res.end(JSON.stringify(pendingPage));
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
        `<div id="app"></div><script>window.__PAGE__=${JSON.stringify(req.url === applicationUrl ? applicationPage : pagePayload())}</script><script type="module" src="/client.js"></script>`,
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
const origin = `http://127.0.0.1:${server.address().port}`;
const takePhoto = async (page, openLabel) => {
    await page.getByRole('button', { name: openLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Take Photo', exact: true }).click();
};
const captureId = async (page) => {
    await takePhoto(page, 'Open Valid ID Camera');
    await page.getByAltText('Valid government ID capture preview').waitFor();
    assert.equal(await page.getByText('Valid ID photo captured', { exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByAltText('Valid government ID capture preview').waitFor();
    assert.equal(await page.getByRole('button', { name: 'Submit Registration' }).count(), 0);
    await takePhoto(page, 'Retake Photo');
    await page.getByAltText('Valid government ID capture preview').waitFor();
    await page.getByRole('button', { name: 'Use this ID photo' }).click();
    await page.getByText('Valid ID photo captured', { exact: true }).waitFor();
};
const legacyDrafts = [
    { extension: 'jpg', mime: 'image/jpeg', step: 5 },
    { extension: 'png', mime: 'image/png', step: 6 },
    { extension: 'pdf', mime: 'application/pdf', step: 4 },
];

const markedDraftCases = [
    { name: 'marked JPEG', mime: 'image/jpeg', accepted: true },
    { name: 'marked PNG', mime: 'image/png', accepted: true },
    { name: 'marked PDF', mime: 'application/pdf' },
    { name: 'empty marked JPEG', mime: 'image/jpeg', content: 'empty' },
    { name: 'oversized marked JPEG', mime: 'image/jpeg', content: 'oversized' },
    { name: 'marker without file', content: 'missing' },
    { name: 'valid image without marker', mime: 'image/png', marker: null },
    { name: 'unsupported marker version', mime: 'image/jpeg', marker: 2 },
    { name: 'non-File attachment', content: 'object' },
    { name: 'malformed record', content: 'record' },
    { name: 'JPEG type with PNG bytes', mime: 'image/jpeg', content: 'mismatch' },
    { name: 'truncated marked PNG', mime: 'image/png', content: 'truncated' },
];

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
        assert.equal(await page.locator('input[type="file"]').count(), 0);
        const legacy = legacyDrafts[index];
        const postsBeforeRecapture = attempts;
        const savedDraft = await page.evaluate(async ({ extension, mime, step, index }) => {
            const key = 'kabarangay.resident-registration.v1';
            const draft = JSON.parse(localStorage.getItem(key));
            const image = mime.startsWith('image/')
                ? await new Promise((resolve) => document.createElement('canvas').toBlob(resolve, mime))
                : '%PDF-legacy';
            const fields = {
                ...draft.fields,
                mobile: '0998000070' + index,
                email: index === 0 ? '' : 'applicant' + index + '@example.test',
            };

            await new Promise((resolve, reject) => {
                const request = indexedDB.open(key, 1);
                request.onerror = () => reject(request.error);
                request.onsuccess = () => {
                    const db = request.result;
                    const tx = db.transaction('draft', 'readwrite');
                    tx.objectStore('draft').put({
                        valid_id: new File([image], 'legacy-id.' + extension, { type: mime }),
                        senior_proof: new File(['%PDF-proof'], 'proof.pdf', { type: 'application/pdf' }),
                    }, 'files');
                    tx.oncomplete = () => { db.close(); resolve(); };
                    tx.onabort = () => { db.close(); reject(tx.error); };
                };
            });
            return { ...draft, step, fields };
        }, { ...legacy, index });
        await page.addInitScript(({ index, savedDraft }) => {
            // The departing page saves on pagehide. Seed the historical
            // draft after that save, before the new page mounts.
            const seeded = 'legacy-draft-seeded-' + index;
            if (sessionStorage.getItem(seeded)) return;
            sessionStorage.setItem(seeded, 'true');
            localStorage.setItem('kabarangay.resident-registration.v1', JSON.stringify(savedDraft));
        }, { index, savedDraft });
        await page.reload();
        await page.getByText(/Capture and confirm a new Valid ID photo/).waitFor();
        assert.equal(await page.getByRole('button', { name: 'Submit Registration' }).count(), 0);
        assert.equal(await page.getByAltText('Valid government ID capture preview').count(), 0);
        assert.deepEqual(await page.evaluate(() => JSON.parse(
            localStorage.getItem('kabarangay.resident-registration.v1')
        ).fields), savedDraft.fields);
        await page.getByRole('button', { name: 'Continue', exact: true }).click();
        await page.getByRole('button', { name: 'Open Valid ID Camera' }).waitFor();
        assert.equal(attempts, postsBeforeRecapture);

        if (index === 0) {
            await page.evaluate(() => {
                const media = navigator.mediaDevices;
                window.restoreFixtureCamera = media.getUserMedia.bind(media);
                media.getUserMedia = () => Promise.reject(new DOMException('Denied', 'NotAllowedError'));
            });
            await page.getByRole('button', { name: 'Open Valid ID Camera' }).click();
            await page.getByRole('alert').filter({ hasText: 'Camera permission was blocked' }).waitFor();
            assert.equal(await page.locator('input[type="file"]').count(), 0);
            await page.evaluate(() => {
                const media = navigator.mediaDevices;
                const requestCamera = window.restoreFixtureCamera;
                // Use a wide simulated frame so both output caps are exercised.
                media.getUserMedia = (constraints) => requestCamera({
                    ...constraints,
                    video: {
                        ...constraints.video,
                        width: { exact: 1600 },
                        height: { exact: 900 },
                    },
                });
                const toBlob = HTMLCanvasElement.prototype.toBlob;
                HTMLCanvasElement.prototype.toBlob = function(callback, type, quality) {
                    window.lastCameraEncoding = { width: this.width, type, quality };
                    return toBlob.call(this, callback, type, quality);
                };
            });
        }
        await captureId(page);
        if (index === 0) {
            assert.deepEqual(await page.evaluate(() => window.lastCameraEncoding), {
                width: 1280,
                type: 'image/jpeg',
                quality: 0.9,
            });
            const preview = page.getByAltText('Valid government ID capture preview');
            const confirmedSrc = await preview.getAttribute('src');
            await page.getByRole('button', { name: 'Retake Photo', exact: true }).click();
            await page.getByRole('button', { name: 'Take Photo', exact: true }).waitFor();
            await page.getByRole('button', { name: 'Cancel', exact: true }).click();
            await preview.waitFor();
            assert.equal(await preview.getAttribute('src'), confirmedSrc);
            await page.getByText('Valid ID photo captured', { exact: true }).waitFor();
            assert.equal(await page.getByRole('button', { name: 'Use this ID photo' }).count(), 0);
        }
        assert.equal(attempts, postsBeforeRecapture);
        await page.waitForFunction(() => new Promise((resolve, reject) => {
            const request = indexedDB.open('kabarangay.resident-registration.v1', 1);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                const db = request.result;
                const read = db.transaction('draft').objectStore('draft').get('files');
                read.onsuccess = () => {
                    const files = read.result;
                    db.close();
                    resolve(files?.validIdCameraVersion === 1 &&
                        files.valid_id?.name.startsWith('valid-id-') &&
                        files.senior_proof?.name === 'proof.pdf');
                };
                read.onerror = () => { db.close(); reject(read.error); };
            };
        }));
        await page
            .getByRole('button', { name: 'Open Camera', exact: true })
            .click();
        await page
            .getByRole('button', { name: 'Take Photo', exact: true })
            .click();
        await page
            .getByText('Live identity photo captured', { exact: true })
            .waitFor();
        if (index === 0) {
            assert.deepEqual(await page.evaluate(() => window.lastCameraEncoding), {
                width: 720,
                type: 'image/jpeg',
                quality: 0.82,
            });
        }

        const continueRegistration = page.getByRole('button', { name: 'Continue', exact: true });
        const mobile = page.getByLabel('Mobile Number', { exact: false });
        await continueRegistration.click();
        await mobile.waitFor();

        if (index === 0) {
            // The cancelled retake let the resident reach Account Security.
            await page.getByRole('button', { name: 'Back', exact: true }).click();
            const preview = page.getByAltText('Valid government ID capture preview');
            await preview.waitFor();
            const idPreviewCard = preview.locator('../..');
            await idPreviewCard.getByRole('button', { name: 'Retake Photo', exact: true }).click();
            await page.getByRole('button', { name: 'Take Photo', exact: true }).click();
            const confirmId = page.getByRole('button', { name: 'Use this ID photo' });
            await confirmId.waitFor();
            assert.equal(await page.getByText('Valid ID photo captured', { exact: true }).count(), 0);
            await continueRegistration.click();
            await page.getByRole('alert').filter({
                hasText: /capture and confirm/i,
            }).waitFor();
            assert.equal(await mobile.count(), 0);
            await confirmId.click();
            await page.getByText('Valid ID photo captured', { exact: true }).waitFor();
            await continueRegistration.click();
            await mobile.waitFor();
        }
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
            assert.match(body, /name="valid_id"; filename="valid-id-\d+\.jpg"/);
            assert.ok(!body.includes('legacy-id.'));
            assert.ok(body.includes('live_identity_photo'));
        }
    }
    assert.equal(attempts, 6);

    const fixtureUrl = origin + '/draft-fixture';
    const draftPage = await browser.newPage();
    draftPage.on('pageerror', (error) => errors.push(error.message));
    await draftPage.route(fixtureUrl, (route) => route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>Draft fixture</title>',
    }));
    for (const testCase of markedDraftCases) {
        await draftPage.goto(fixtureUrl);
        const expectedFields = await draftPage.evaluate(async ({ mime, content, marker = 1, accepted }) => {
            const key = 'kabarangay.resident-registration.v1';
            const fields = { first_name: 'Draft Resident', address_line: 'Draft Street', mobile: '09980000701' };
            const canvas = document.createElement('canvas');
            const image = await new Promise((resolve) => canvas.toBlob(resolve, content === 'mismatch' ? 'image/png' : mime));
            const attachments = {
                senior_proof: new File(['%PDF-proof'], 'proof.pdf', { type: 'application/pdf' }),
                ...(marker === null ? {} : { validIdCameraVersion: marker }),
            };
            if (content !== 'missing') {
                const parts = {
                    empty: [],
                    oversized: [image, new Uint8Array(5 * 1024 * 1024)],
                    truncated: [image.slice(0, 8)],
                };
                attachments.valid_id = content === 'object'
                    ? { name: 'id.jpg', size: 100, type: 'image/jpeg' }
                    : new File(parts[content] ?? [image], 'saved-id', { type: mime });
            }
            await new Promise((resolve, reject) => {
                const request = indexedDB.open(key, 1);
                request.onupgradeneeded = () => request.result.createObjectStore('draft');
                request.onerror = () => reject(request.error);
                request.onsuccess = () => {
                    const db = request.result;
                    const tx = db.transaction('draft', 'readwrite');
                    tx.objectStore('draft').put(content === 'record' ? [attachments] : attachments, 'files');
                    tx.oncomplete = () => { db.close(); resolve(); };
                    tx.onabort = () => { db.close(); reject(tx.error); };
                };
            });
            localStorage.setItem(key, JSON.stringify({ version: 1, step: accepted ? 4 : 5, fields }));
            return fields;
        }, testCase);
        await draftPage.goto(origin + '/resident/register');
        if (testCase.accepted) {
            const preview = draftPage.getByAltText('Valid government ID capture preview');
            await preview.waitFor();
            assert.ok(await preview.evaluate(async (image) => {
                await image.decode();
                return image.naturalWidth > 0;
            }), testCase.name);
            assert.equal(await draftPage.getByText('Valid ID photo captured', { exact: true }).count(), 1);
        } else {
            await draftPage.getByText(/Capture and confirm a new Valid ID photo/).waitFor();
            assert.equal(await draftPage.getByAltText('Valid government ID capture preview').count(), 0, testCase.name);
            assert.equal(await draftPage.getByRole('button', { name: 'Submit Registration' }).count(), 0, testCase.name);
            assert.equal(await draftPage.getByRole('button', { name: 'Open Valid ID Camera' }).count(), 1, testCase.name);
            if (testCase.content !== 'record') {
                await draftPage.waitForFunction(() => new Promise((resolve, reject) => {
                    const request = indexedDB.open('kabarangay.resident-registration.v1', 1);
                    request.onerror = () => reject(request.error);
                    request.onsuccess = () => {
                        const db = request.result;
                        const read = db.transaction('draft').objectStore('draft').get('files');
                        read.onsuccess = async () => {
                            db.close();
                            const files = read.result;
                            resolve(files?.valid_id === null &&
                                files.validIdCameraVersion === undefined &&
                                await files.senior_proof?.text() === '%PDF-proof');
                        };
                        read.onerror = () => { db.close(); reject(read.error); };
                    };
                }));
            }
        }
        const storedFields = await draftPage.evaluate(() => JSON.parse(
            localStorage.getItem('kabarangay.resident-registration.v1')
        ).fields);
        for (const [field, value] of Object.entries(expectedFields)) {
            assert.equal(storedFields[field], value, testCase.name + ': ' + field);
        }
        assert.equal(attempts, 6, testCase.name);
    }
    await draftPage.close();
    console.log(`${markedDraftCases.length} saved Valid ID checks passed.`);

    await page.goto(origin + applicationUrl);
    assert.equal(await page.locator('input[type="file"]').count(), 1);
    await page.getByLabel('Senior supporting document').setInputFiles({
        name: 'support.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('%PDF-support'),
    });
    await takePhoto(page, 'Open Valid ID Camera');
    await page.getByAltText('Valid government ID capture preview').waitFor();
    await page.getByRole('button', { name: 'Resubmit correction' }).click();
    await page.getByText('Please complete every required correction document before resubmitting.').waitFor();
    assert.equal(correctionSubmission, undefined);
    await takePhoto(page, 'Retake Photo');
    await page.getByAltText('Valid government ID capture preview').waitFor();
    await page.getByRole('button', { name: 'Use this ID photo' }).click();
    await page.getByRole('button', { name: 'Resubmit correction' }).click();
    await page.getByText('Pending review', { exact: true }).waitFor();
    assert.match(correctionSubmission.contentType, /^multipart\/form-data;/);
    assert.match(correctionSubmission.body, /name="documents\[valid_id\]"; filename="valid-id-\d+\.jpg"/);
    assert.match(correctionSubmission.body, /name="documents\[senior_proof\]"; filename="support\.pdf"/);

    await page.goto(origin + applicationUrl);
    await page.evaluate(() => {
        const media = navigator.mediaDevices;
        const getUserMedia = media.getUserMedia.bind(media);
        media.getUserMedia = (constraints) => new Promise((resolve, reject) => {
            window.releaseFixtureCamera = async () => {
                try {
                    window.lateFixtureStream = await getUserMedia(constraints);
                    resolve(window.lateFixtureStream);
                } catch (error) {
                    reject(error);
                }
            };
        });
    });
    await page.getByRole('button', { name: 'Open Valid ID Camera' }).click();
    await page.waitForFunction(() => typeof window.releaseFixtureCamera === 'function');
    await page.evaluate((url) => window.visitRegistrationFixture(url), pendingPage.url);
    await page.getByRole('heading', { name: 'Account is waiting for admin approval' }).waitFor();
    await page.evaluate(() => window.releaseFixtureCamera());
    await page.waitForFunction(() => window.lateFixtureStream?.getTracks().length > 0 &&
        window.lateFixtureStream.getTracks().every((track) => track.readyState === 'ended'));
    assert.deepEqual(errors, []);
    console.log(
        'Registration and correction camera checks passed, including encoding, confirmation, cancel-retake, replacement, multipart upload, legacy JPG/PNG/PDF drafts at steps 4–6, permission denial, late camera cleanup, and registration retry.',
    );
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
