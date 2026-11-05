import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/components/certificates/SicsicanCertificatePreview.tsx', import.meta.url), 'utf8');
const js = ts.transpileModule(source, {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.ESNext },
}).outputText.replace('"react/jsx-runtime"', JSON.stringify(pathToFileURL(require.resolve('react/jsx-runtime')).href));
const { default: Preview } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`);
const incumbent = { id: 1, name: 'Published Captain', title: 'Punong Barangay' };
const props = { name: 'Resident', purok: 'Banaba', purpose: 'Employment', years: '6', businessName: 'Shop', businessAddress: 'Banaba', captain: incumbent, reference: '2026-11-0001' };
const render = (type, extra = {}) => renderToStaticMarkup(React.createElement(Preview, { ...props, type, ...extra }));

for (const type of ['Barangay Certification', 'Sicsican Indigency', 'Sicsican Clearance', 'Business Clearance']) {
    test(`${type} uses published captain and selected authority without sample officials`, () => {
        const html = render(type, { officerName: 'Selected Officer' });
        assert.match(html, /PUBLISHED CAPTAIN/);
        assert.match(html, /SELECTED OFFICER/);
        assert.match(html, /By Authority of the Punong Barangay:/);
        assert.doesNotMatch(html, /JONATHAN|MARK M\. ELLO|OSCAR ANSGAR|Acting Punong Barangay/);
        const empty = render(type);
        assert.match(empty, /<strong>___________________<\/strong>/);
    });
    test(`${type} hides authority when an OIC is selected and restores it afterward`, () => {
        const html = render(type, { captain: { id: 2, name: 'Selected OIC', title: 'Barangay Kagawad, OIC' }, officerName: 'Selected Officer' });
        assert.match(html, /SELECTED OIC/);
        assert.match(html, /Barangay Kagawad, OIC/);
        assert.doesNotMatch(html, /PUBLISHED CAPTAIN|By Authority of the Punong Barangay:|SELECTED OFFICER/);
        assert.match(render(type, { officerName: 'Selected Officer' }), /By Authority of the Punong Barangay:/);
    });
}
