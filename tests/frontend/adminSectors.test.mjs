import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/pages/Admin/Sectors.tsx', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        jsx: ts.JsxEmit.ReactJSX,
        target: ts.ScriptTarget.ES2022,
    },
}).outputText;
const exports = {};
let loading = true;
runInNewContext(compiled, {
    exports,
    require: (name) => {
        if (name === '@inertiajs/react') return {
            Head: () => null,
            Link: ({ children }) => children,
            Deferred: ({ fallback, children }) => loading ? fallback : children,
            router: {},
        };
        if (name === '@/layouts/AdminLayout') return { default: ({ children }) => children };
        if (name === '@/components/AdminPageSkeleton') return { default: () => 'Loading sectors' };
        return require(name);
    },
});

for (const tab of ['applications', 'general', 'pwd', 'senior', 'certificates', 'lupon']) {
    test(`Sectors ${tab} renders while deferred data is absent, then after it loads`, () => {
        const props = {
            filters: { search: '', purok: '' },
            certificateFilters: { search: '' },
            initialTab: tab,
        };
        loading = true;
        // Covers a fresh visit, refresh, and history entries without deferred props.
        assert.match(renderToStaticMarkup(createElement(exports.default, props)), /Loading sectors/);
        loading = false;
        const paginator = { data: [], current_page: 1, last_page: 1, total: 0, from: null, to: null };
        const html = renderToStaticMarkup(createElement(exports.default, {
            ...props,
            stats: { sectorApplications: 0, generalMembers: 0, pwdMembers: 0, seniorMembers: 0, certificates: 0, openCases: 0 },
            generalMembers: paginator,
            pwdMembers: paginator,
            seniorMembers: paginator,
            puroks: [],
            certificates: { processed: 0, completed: 0 },
            certificateRequests: paginator,
        }));
        assert.match(html, /Consolidated resident sector rosters/);
        assert.doesNotMatch(html, /Loading sectors/);
    });
}
