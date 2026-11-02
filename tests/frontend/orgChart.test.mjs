import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import test from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const asModule = (text) => `data:text/javascript;base64,${Buffer.from(text).toString('base64')}`;
const helperSource = await readFile(new URL('../../resources/js/components/org-chart/orgChart.ts', import.meta.url), 'utf8');
const helperUrl = asModule(ts.transpileModule(helperSource, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } }).outputText);
const { groupOrgChart } = await import(helperUrl);
const componentSource = await readFile(new URL('../../resources/js/components/org-chart/BarangayOrgChart.tsx', import.meta.url), 'utf8');
const componentJs = ts.transpileModule(componentSource, { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } }).outputText
    .replace(/(['"])\.\/orgChart\1/g, JSON.stringify(helperUrl))
    .replace(/(['"])react\/jsx-runtime\1/g, JSON.stringify(pathToFileURL(require.resolve('react/jsx-runtime')).href));
const { default: Chart } = await import(asModule(componentJs));
const position = (id, title, name, extra = {}) => ({ id, title, assigned_person: name, parent_id: null, status: 'published', has_photo: false, photo_url: null, updated_at: null, children: [], ...extra });
const fixture = () => [position(1, 'Punong Barangay', 'Default Captain', { children: [
    ...Array.from({ length: 7 }, (_, index) => position(index + 2, `Kagawad on Committee ${index + 1}`, `Council Member ${index + 1}`, { parent_id: 1 })),
    position(9, 'Barangay Secretary', 'Secretary Person', { parent_id: 1 }),
    position(10, 'Barangay Treasurer', 'Treasurer Person', { parent_id: 1 }),
] })];

test('groups all standard roles and preserves the input hierarchy', () => {
    const tree = fixture();
    const original = structuredClone(tree);
    const groups = groupOrgChart(tree);
    assert.equal(groups.captain.id, 1);
    assert.equal(groups.isActing, false);
    assert.equal(groups.kagawads.length, 7);
    assert.deepEqual(groups.appointed.map((member) => member.id), [9, 10]);
    assert.deepEqual(tree, original);
});

test('an explicit acting Kagawad becomes the sole root without appearing in the legislative row', () => {
    const tree = fixture();
    const acting = tree[0].children[0];
    tree.push(position(20, 'Kagawad on Peace', ' COUNCIL   MEMBER 1 '));
    const groups = groupOrgChart(tree, acting);
    assert.equal(groups.captain.id, acting.id);
    assert.equal(groups.isActing, true);
    assert.equal(groups.kagawads.length, 6);
    assert.ok(!groups.kagawads.some((member) => member.id === acting.id || member.id === 20));
});

test('recognizes a published acting position while ignoring a vacant acting role', () => {
    const tree = fixture();
    tree.push(position(11, 'Acting Punong Barangay', null));
    assert.equal(groupOrgChart(tree).captain.id, 1);
    tree.push(position(12, 'Acting Punong Barangay', 'Acting Official'));
    assert.equal(groupOrgChart(tree).captain.id, 12);
    assert.equal(groupOrgChart(tree).isActing, true);
});

test('keeps other positions and vacancies visible', () => {
    const tree = fixture();
    tree.push(position(11, 'Barangay Health Worker', null));
    const groups = groupOrgChart(tree);
    assert.equal(groups.others[0].id, 11);
    const html = renderToStaticMarkup(createElement(Chart, { tree }));
    assert.match(html, /Other Barangay Positions/);
    assert.match(html, /Vacant/);
});

test('renders the requested levels in order with accessible card selection', () => {
    const html = renderToStaticMarkup(createElement(Chart, { tree: fixture(), selectedId: 3, onSelect: () => {}, showStatus: true }));
    assert.ok(html.indexOf('Default Captain') < html.indexOf('Sangguniang Barangay'));
    assert.ok(html.indexOf('Council Member 7') < html.indexOf('Appointed Officials'));
    assert.match(html, /Secretary Person/);
    assert.match(html, /Treasurer Person/);
    assert.equal((html.match(/aria-pressed="true"/g) || []).length, 1);
    assert.equal((html.match(/<button/g) || []).length, 10);
});

test('renders the acting badge once and safely escapes names', () => {
    const tree = fixture();
    const acting = { ...tree[0].children[0], assigned_person: 'Alice <script> & Reyes' };
    const html = renderToStaticMarkup(createElement(Chart, { tree, actingCaptain: acting }));
    assert.equal((html.match(/\(Acting\)/g) || []).length, 1);
    assert.match(html, /Alice &lt;script&gt; &amp; Reyes/);
    assert.ok(!html.includes('<script>'));
    assert.ok(!html.includes('Default Captain'));
});

test('does not invent an official when a captain has not been assigned', () => {
    const html = renderToStaticMarkup(createElement(Chart, { tree: [position(9, 'Barangay Secretary', 'Secretary Person')] }));
    assert.match(html, /Not assigned/);
    assert.match(html, /Secretary Person/);
    assert.ok(!html.includes('(Acting)'));
});