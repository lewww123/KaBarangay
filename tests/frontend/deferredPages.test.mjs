import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { createRequire } from "node:module";
import { resolve, dirname } from "node:path";
import test from "node:test";
import { runInNewContext } from "node:vm";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";

const require = createRequire(import.meta.url);
const root = resolve("resources/js");
const pageRoot = resolve(root, "pages");
const loading = { value: true };
const props = {
    auth: { user: { id: 1, name: "Test Staff", roles: [], staffAssignments: [] } },
    userName: "Test Staff",
    filters: { search: "", purok: "", category: "all", period: "month", date: "2026-11-06" },
    certificateFilters: {},
    initialTab: "applications",
    kind: "auto",
    window: {
        from: "2026-11-01",
        to: "2026-11-30",
        label: "November",
        timezone: "Asia/Manila",
        boundary: "",
    },
};
const cache = new Map();
function load(file) {
    if (cache.has(file)) return cache.get(file);
    const compiled = ts.transpileModule(readFileSync(file, "utf8"), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            jsx: ts.JsxEmit.ReactJSX,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const exports = {};
    cache.set(file, exports);
    const passthrough = ({ children }) => children;
    runInNewContext(compiled, {
        exports,
        console,
        URL,
        URLSearchParams,
        setTimeout,
        clearTimeout,
        require: (name) => {
            if (name === "@inertiajs/react")
                return {
                    Head: () => null,
                    Link: passthrough,
                    Deferred: ({ fallback, children }) =>
                        loading.value
                            ? typeof fallback === "function"
                                ? fallback()
                                : fallback
                            : typeof children === "function"
                              ? children({ reloading: false })
                              : children,
                    usePage: () => ({ props }),
                    router: {},
                    useForm: (data) => ({
                        data,
                        errors: {},
                        processing: false,
                        setData() {},
                        post() {},
                        put() {},
                        reset() {},
                    }),
                    usePoll() {},
                };
            if (name === "@/components/withDeferredPage")
                return load(resolve(root, "components/withDeferredPage.tsx"));
            if (name.includes("PageSkeleton")) return { default: () => "Loading page" };
            if (name.startsWith("@/layouts/")) return { default: passthrough };
            if (name.startsWith("@/components/")) {
                if (/\/(announcement|types|caseGuide|caseWorkspace)$/.test(name)) {
                    const base = resolve(root, name.slice(2));
                    return load(base + ".ts");
                }
                return new Proxy(
                    { default: () => null },
                    { get: (obj, key) => obj[key] ?? (() => null) },
                );
            }
            if (name.startsWith("@/hooks/"))
                return { default: () => ({ data: [], refresh() {}, reload() {} }) };
            if (name.startsWith("@/") || name.startsWith(".")) {
                const base = name.startsWith("@/")
                    ? resolve(root, name.slice(2))
                    : resolve(dirname(file), name);
                for (const ext of [".ts", ".tsx"]) {
                    try {
                        return load(base + ext);
                    } catch (e) {
                        if (e.code !== "ENOENT") throw e;
                    }
                }
            }
            return require(name);
        },
    });
    return exports;
}
function files(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
        e.isDirectory() ? files(resolve(dir, e.name)) : [resolve(dir, e.name)],
    );
}
for (const file of files(pageRoot).filter((f) => f.endsWith(".tsx"))) {
    const source = readFileSync(file, "utf8");
    if (!/<Deferred\b|export default withDeferredPage\(/.test(source)) continue;
    test(`${file.slice(pageRoot.length + 1)} renders its loading screen before deferred props exist`, () => {
        loading.value = true;
        const html = renderToStaticMarkup(createElement(load(file).default, props));
        assert.match(html, /Loading page/);
    });
}

for (const page of [
    "Pwd/Dashboard",
    "Senior/Dashboard",
    "Pwd/Announcements/Index",
    "Senior/Announcements/Index",
    "Admin/AnnouncementApprovals",
]) {
    test(`${page} renders real content after deferred props arrive`, () => {
        loading.value = false;
        const html = renderToStaticMarkup(
            createElement(load(resolve(pageRoot, page + ".tsx")).default, {
                ...props,
                stats: {
                    totalResidents: 7,
                    totalAnnouncements: 1,
                    pendingAnnouncements: 0,
                    draftPrograms: 0,
                    approvedAnnouncements: 1,
                },
                recentAnnouncements: [],
                announcements: [],
                tabCounts: {
                    all: 0,
                    pending: 0,
                    approved: 0,
                    rejected: 0,
                    draft: 0,
                    ongoing: 0,
                    done: 0,
                },
            }),
        );
        assert.ok(html.length > 0);
        assert.doesNotMatch(html, /Loading page/);
    });
}
