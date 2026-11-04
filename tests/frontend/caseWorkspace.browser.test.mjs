import assert from 'node:assert/strict';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { resolve, relative } from 'node:path';
import { createRequire } from 'node:module';
import { createServer } from 'vite-plus';
import react from '@vitejs/plugin-react';
import tailwind from '@tailwindcss/vite';

// Uses existing local tools; no application server, database or mutation request.
const require = createRequire(import.meta.url);
const { chromium } = require(
    process.env.PLAYWRIGHT_MODULE ||
        'C:/Users/ACER/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright',
);
const root = resolve('.');
const parent = resolve('tests/frontend');
const dir = await mkdtemp(resolve(parent, '.tmp-case-workspace-'));
let server, browser;
try {
    await writeFile(
        resolve(dir, 'index.html'),
        '<div id="root"></div><script type="module" src="./fixture.tsx"></script>',
    );
    await writeFile(
        resolve(dir, 'preview.tsx'),
        `import {useEffect} from 'react'; export default function Preview({onReady}){useEffect(()=>{onReady?.(true)},[onReady]);return <p>Document renderer excluded from workspace UI test.</p>}`,
    );
    await writeFile(
        resolve(dir, 'print.ts'),
        `export async function printCertificateDocument(blob,title){window.printTest={size:blob.size,title}}`,
    );
    await writeFile(
        resolve(dir, 'style.css'),
        '@import "tailwindcss" source(none);\n@source "../../../resources/js/components/lupon";',
    );
    await writeFile(
        resolve(dir, 'fixture.tsx'),
        `
import React from 'react';
import {createRoot} from 'react-dom/client';
import './style.css';
import CaseWorkspace from '/resources/js/components/lupon/CaseWorkspace';
import CaseOverview from '/resources/js/components/lupon/CaseOverview';
import HearingRecords from '/resources/js/components/lupon/HearingRecords';
import WorkflowPanel from '/resources/js/components/lupon/WorkflowPanel';
import DocumentPanel from '/resources/js/components/lupon/DocumentPanel';
import CaseTimeline from '/resources/js/components/lupon/CaseTimeline';
const workflow = {status:'mediation_active',statusLabel:'Mediation active',trackingStatus:'Under Review',transitions:['pangkat_formed','dismissed'],milestones:{mediation_started_at:'2026-11-02',pangkat_formed_at:null,conciliation_started_at:null},deadlines:[],events:[{id:2,from:'filed',to:'mediation_active',date:'2026-11-02',notes:'Second supplied event'},{id:1,from:null,to:'filed',date:'2026-11-01',notes:'First supplied event'}]};
if (location.search === '?closed') Object.assign(workflow, {status:'dismissed',transitions:[],events:[...workflow.events,{id:3,from:'mediation_active',to:'dismissed',date:'2026-11-03',notes:'Supplied dismissal record'}]});
const hearings = [{id:12,hearingNumber:2,phase:'Kapitan',date:'2026-11-05',time:'10:00',venue:'Barangay Hall',status:'Scheduled',notes:'Supplied hearing note',officerName:'Fixture officer'}];
hearings.push({...hearings[0],id:11,hearingNumber:1,date:'2026-11-01',status:'Held',notes:'Earlier saved hearing'}, {...hearings[0],id:13,hearingNumber:3,date:'2026-11-08',status:'Rescheduled',notes:'Another saved hearing'});
const documents = [{id:1,kind:'summons',label:'Fixture summons',status:'ready',hearingId:12,generatedAt:'2026-11-02T10:00:00',servedAt:null,url:'/fixture.docx'},{id:2,kind:'cfa',label:'Fixture case document',status:'pending',hearingId:null,generatedAt:null,servedAt:null,url:null}];
const caseData = {status:'mediation_active',phase:'Kapitan',filedDate:'2026-11-01',assignedStaffName:'Fixture staff',complainants:[{id:1,name:'Fixture complainant',address:'Address A',isPrimary:true}],respondents:[{id:2,name:'Fixture respondent',address:'Address B',isPrimary:true}],intake:{incidentDate:'2026-10-30',narrative:'Supplied narrative',requestedAction:'Supplied request'},workflow};
createRoot(document.getElementById('root')).render(<main className="mx-auto max-w-5xl space-y-4 p-4"><CaseWorkspace workflow={workflow} hearings={hearings} documents={documents} hasSettlement={false} onSchedule={()=>{}} overview={<CaseOverview caseData={caseData}/>} panels={{resolution:<WorkflowPanel caseId={99} workflow={workflow}/>,details:<label>Party draft<input aria-label="Party draft"/></label>,hearings:<HearingRecords hearings={hearings} hasSettlement={false} documents={documents}/>,documents:<DocumentPanel caseId={99} status="mediation_active" paymentStatus="paid" documents={documents} officials={{punong_barangay:null,active_kagawads:[],kagawads:[]}} hearings={hearings} hasSettlement={false} onSchedule={()=>{}}/>,history:<CaseTimeline workflow={workflow}/>}}/></main>);
`,
    );
    server = await createServer({
        configFile: false,
        root,
        plugins: [react(), tailwind()],
        resolve: {
            alias: {
                '@/components/DocxPreview': resolve(dir, 'preview.tsx'),
                '@/lib/certificateDocument': resolve(dir, 'print.ts'),
                '@': resolve('resources/js'),
            },
        },
        server: { host: '127.0.0.1', port: 5189, strictPort: true },
        logLevel: 'error',
    });
    await server.listen();
    browser = await chromium.launch({
        executablePath:
            process.env.CHROME_PATH ||
            'C:/Program Files/Google/Chrome/Application/chrome.exe',
        headless: true,
    });
    const page = await browser.newPage({
        viewport: { width: 1280, height: 900 },
    });
    page.setDefaultTimeout(60000);
    const errors = [];
    page.on('pageerror', (error) => {
        errors.push(error.message);
        console.error('Browser error:', error.message);
    });
    await page.route('**/*', (route) =>
        ['GET', 'HEAD'].includes(route.request().method())
            ? route.continue()
            : route.abort(),
    );
    let printFetches = 0;
    await page.route('**/lupon/documents/1/preview', (route) => {
        printFetches++;
        return route.fulfill({
            status: 200,
            body: Buffer.from([0x50, 0x4b, 0, 0]),
            contentType:
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        });
    });
    const url =
        'http://127.0.0.1:5189/' +
        relative(root, resolve(dir, 'index.html')).replaceAll('\\', '/');
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 120000 });
    console.log('Fixture loaded');
    const tab = (name) => page.getByRole('tab', { name, exact: true });
    await tab('Overview').waitFor();
    page.setDefaultTimeout(15000);
    console.log('Overview rendered');
    assert.equal(await page.getByRole('tab').count(), 4);
    assert.equal(await tab('Overview').getAttribute('aria-selected'), 'true');
    const overview = page.getByRole('tabpanel', {
        name: 'Overview',
        exact: true,
    });
    await overview.getByText('Fixture complainant', { exact: false }).waitFor();
    await overview.getByText('Fixture respondent', { exact: false }).waitFor();
    await overview
        .getByText('Read salaysay and requested action', { exact: true })
        .click();
    await overview.getByText('Supplied narrative', { exact: true }).waitFor();
    for (const text of [
        'Current stage',
        'Process records',
        'Assigned staff',
        'Status / stage',
        'Review the scheduled hearing',
        'Current-stage actions and recorded settlement',
        'Manage parties, complaint details and evidence',
    ]) {
        assert.equal(
            await overview.getByText(text, { exact: true }).count(),
            0,
        );
    }
    assert.equal(await overview.getByRole('button').count(), 0);
    assert.equal(await overview.getByRole('combobox').count(), 0);
    await tab('Documents').click();
    assert.equal(await tab('Documents').getAttribute('aria-selected'), 'true');
    console.log('Checking documents');
    await page
        .getByRole('heading', { name: 'Hearing #2', exact: true })
        .waitFor();
    await page.getByRole('heading', { name: 'Case-level documents' }).waitFor();
    await page
        .getByText('No service record supplied', { exact: false })
        .waitFor();
    await page
        .getByRole('button', { name: 'Record service', exact: true })
        .click();
    await page.getByRole('dialog', { name: 'Record notice service' }).waitFor();
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page
        .getByRole('button', { name: 'Preview', exact: true })
        .first()
        .click();
    await page
        .getByRole('dialog', { name: 'Fixture summons', exact: true })
        .waitFor();
    await page
        .getByRole('button', { name: 'Print document', exact: true })
        .click();
    await page.waitForFunction(
        () => window.printTest?.title === 'Fixture summons',
    );
    assert.equal(printFetches, 1);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await tab('Hearings').click();
    console.log('Checking hearings');
    assert.equal(
        await page.locator('#case-panel-hearings > section').count(),
        1,
    );
    await page
        .getByRole('button', { name: 'Open hearing records (3)', exact: true })
        .click();
    const modal = page.getByRole('dialog', {
        name: 'Case hearing records',
        exact: true,
    });
    await modal.waitFor();
    assert.equal(
        await modal
            .getByRole('navigation', { name: 'Saved hearings' })
            .getByRole('button')
            .count(),
        3,
    );
    assert.deepEqual(
        await modal
            .getByRole('navigation', { name: 'Saved hearings' })
            .getByRole('button')
            .locator('span:first-child')
            .allTextContents(),
        ['Hearing #1', 'Hearing #2', 'Hearing #3'],
    );
    await modal
        .locator('p')
        .filter({ hasText: /^Supplied hearing note$/ })
        .waitFor();
    await modal.locator('summary').click();
    assert.equal(
        await modal
            .locator('select')
            .nth(1)
            .locator('option')
            .last()
            .evaluate((option) => option.disabled),
        true,
    );
    await modal
        .getByLabel('Hearing record / notes')
        .fill('Draft for hearing two');
    await modal
        .getByRole('navigation', { name: 'Saved hearings' })
        .getByRole('button', { name: /Hearing #1/ })
        .click();
    await modal.locator('summary').click();
    await modal
        .getByLabel('Hearing record / notes')
        .fill('Draft for hearing one');
    await modal
        .getByRole('navigation', { name: 'Saved hearings' })
        .getByRole('button', { name: /Hearing #2/ })
        .click();
    await modal.locator('summary').click();
    assert.equal(
        await modal.getByLabel('Hearing record / notes').inputValue(),
        'Draft for hearing two',
    );
    await page.keyboard.press('Escape');
    assert.equal(await modal.isVisible(), false);
    await page
        .getByRole('button', { name: 'Open hearing records (3)', exact: true })
        .click();
    await modal.locator('summary').click();
    assert.equal(
        await modal.getByLabel('Hearing record / notes').inputValue(),
        'Draft for hearing two',
    );
    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        assert.equal(
            await modal.evaluate(
                (element) => element.scrollWidth <= element.clientWidth,
            ),
            true,
            'Hearing popup overflows at ' + width,
        );
        const cards = await modal
            .getByRole('navigation', { name: 'Saved hearings' })
            .boundingBox();
        const details = await modal
            .getByRole('region', { name: 'Selected hearing details' })
            .boundingBox();
        assert.equal(
            width === 1280
                ? details.x > cards.x + cards.width
                : details.y >= cards.y + cards.height,
            true,
            'Hearing popup layout at ' + width,
        );
        if (process.env.CASE_WORKSPACE_SCREENSHOTS)
            await page.screenshot({
                path: resolve(
                    process.env.CASE_WORKSPACE_SCREENSHOTS,
                    'lupon-hearings-' + width + '.png',
                ),
                fullPage: true,
            });
    }
    await modal
        .getByRole('button', { name: 'Close records', exact: true })
        .click();
    await tab('History').click();
    console.log('Checking history');
    const history = await page
        .locator('#case-panel-history ol li')
        .allTextContents();
    assert.match(history[0], /First supplied event/);
    assert.match(history[1], /Second supplied event/);
    await tab('Overview').click();
    await tab('Overview').focus();
    await page.keyboard.press('ArrowRight');
    assert.equal(await tab('Hearings').getAttribute('aria-selected'), 'true');
    await page.keyboard.press('End');
    assert.equal(await tab('History').getAttribute('aria-selected'), 'true');
    await page.keyboard.press('Home');
    assert.equal(await tab('Overview').getAttribute('aria-selected'), 'true');
    for (const width of [390, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        for (const name of ['Overview', 'Hearings', 'Documents', 'History']) {
            await tab(name).click();
            assert.equal(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                true,
                name + ' overflow at ' + width,
            );
        }
    }
    await page.goto(url + '?closed', { waitUntil: 'domcontentloaded' });
    await tab('Overview').waitFor();
    assert.equal(
        await page.getByText('Process records', { exact: true }).count(),
        0,
    );
    assert.equal(
        await page
            .getByRole('button', { name: 'I-save ang case action' })
            .count(),
        0,
    );
    assert.deepEqual(errors, []);
    console.log(
        'PASS: four tabs/default, filing-only overview, hearing drafts, hearing/document modals, document grouping/service separation, chronological history, keyboard navigation, responsive widths 390/768/1280. No mutation requests sent.',
    );
} finally {
    console.log('Closing frontend test harness');
    await browser?.close();
    await server?.close();
    if (
        resolve(dir).startsWith(parent + '/') ||
        resolve(dir).startsWith(parent + '\\')
    )
        await rm(dir, { recursive: true, force: true });
}
