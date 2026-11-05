import assert from "node:assert/strict";
import { createServer } from "node:http";
import { mkdtemp, writeFile, rm } from "node:fs/promises";
import { createRequire } from "node:module";
import { resolve, join } from "node:path";
import { build } from "vite-plus";
import react from "@vitejs/plugin-react";
const require = createRequire(import.meta.url);
const { chromium } = require(
    process.env.PLAYWRIGHT_MODULE ||
        "C:/Users/ACER/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright",
);
const root = resolve("tests/frontend");
const dir = await mkdtemp(join(root, ".tmp-deferred-"));
let bundle;
try {
    await writeFile(
        join(dir, "entry.tsx"),
        `import React from 'react';import {createRoot} from 'react-dom/client';import {createInertiaApp,router} from '@inertiajs/react';import PD from '/resources/js/pages/Pwd/Dashboard';import SD from '/resources/js/pages/Senior/Dashboard';import PA from '/resources/js/pages/Pwd/Announcements/Index';import SA from '/resources/js/pages/Senior/Announcements/Index';import AP from '/resources/js/pages/Admin/AnnouncementApprovals';const pages={PD,SD,PA,SA,AP};window.__visit=(url)=>router.get(url);createInertiaApp({page:window.__PAGE__,resolve:name=>pages[name],setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});`,
    );
    const result = await build({
        configFile: false,
        root: resolve("."),
        plugins: [react()],
        resolve: { alias: { "@": resolve("resources/js") } },
        define: { "process.env.NODE_ENV": '"production"' },
        logLevel: "error",
        build: { write: false, lib: { entry: join(dir, "entry.tsx"), formats: ["es"] } },
    });
    bundle = (Array.isArray(result) ? result[0] : result).output.find(
        (item) => item.type === "chunk" && item.isEntry,
    ).code;
} finally {
    if (!resolve(dir).startsWith(root + "\\") && !resolve(dir).startsWith(root + "/"))
        throw new Error("Invalid fixture path");
    await rm(dir, { recursive: true, force: true });
}
const cases = [
    ["/pwd/dashboard", "PD", "auto"],
    ["/senior/dashboard", "SD", "auto"],
    ...["pwd", "senior"].flatMap((sector) =>
        ["announcements", "updates", "programs"].map((kind) => [
            `/${sector}/${kind}`,
            sector === "pwd" ? "PA" : "SA",
            kind === "updates" ? "update" : kind === "programs" ? "program" : "auto",
        ]),
    ),
    ["/admin/announcement-approvals", "AP", "auto"],
];
const item = {
    id: 1,
    title: "Deferred program loaded",
    content: "Test program",
    category: "Event",
    requires_attendance: false,
    approval_status: "approved",
    program_status: "ongoing",
    is_published: true,
    created_at: "2026-11-06T00:00:00Z",
};
let deferredRequests = 0;
const server = createServer((req, res) => {
    if (req.url === "/client.js") {
        res.setHeader("Content-Type", "text/javascript");
        return res.end(bundle);
    }
    const url = new URL(req.url, "http://localhost");
    const config = cases.find(([path]) => path === url.pathname);
    if (!config) {
        res.statusCode = 404;
        return res.end();
    }
    const [, component, kind] = config;
    const data = ["PD", "SD"].includes(component)
        ? {
              stats: {
                  totalResidents: 7,
                  totalAnnouncements: 1,
                  pendingAnnouncements: 0,
                  draftPrograms: 0,
                  approvedAnnouncements: 1,
              },
              recentAnnouncements: [item],
          }
        : component === "AP"
          ? { announcements: [item] }
          : { announcements: [item], tabCounts: { all: 1, draft: 0, ongoing: 1, done: 0 } };
    const props = {
        errors: {},
        flash: {},
        auth: { user: { id: 1, name: "Test Staff", role: "admin", staffAssignments: [] } },
        userName: "Test Staff",
        kind,
        activeTab: "all",
    };
    const partial = !!req.headers["x-inertia-partial-data"];
    const loaded = partial || url.searchParams.has("preloaded");
    const page = {
        component,
        props: { ...props, ...(loaded ? data : {}) },
        url: req.url,
        version: "test",
        ...(!loaded ? { deferredProps: { default: Object.keys(data) } } : {}),
    };
    if (req.headers["x-inertia"]) {
        res.setHeader("Content-Type", "application/json");
        res.setHeader("X-Inertia", "true");
        if (partial) {
            deferredRequests++;
            return setTimeout(() => res.end(JSON.stringify(page)), 400);
        }
        return res.end(JSON.stringify(page));
    }
    res.setHeader("Content-Type", "text/html");
    res.end(
        `<div id="app"></div><script>window.__PAGE__=${JSON.stringify(page)}</script><script type="module" src="/client.js"></script>`,
    );
});
await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
let browser;
try {
    browser = await chromium.launch({
        headless: true,
        channel: process.env.PLAYWRIGHT_CHANNEL || "chrome",
    });
    const page = await browser.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const base = `http://127.0.0.1:${server.address().port}`;
    for (const [path] of cases) {
        await page.goto(base + path);
        await page.getByRole("status", { name: "Loading data" }).waitFor();
        await page.getByRole("status", { name: "Loading data" }).waitFor({ state: "detached" });
        await page.getByText(item.title, { exact: true }).first().waitFor();
        await page.reload();
        await page.getByText(item.title, { exact: true }).first().waitFor();
        await page.goto(base + path + "?preloaded=1");
        await page.getByText(item.title, { exact: true }).first().waitFor();
    }
    await page.goto(base + cases[0][0] + "?preloaded=1");
    for (const [path] of cases.slice(1)) {
        await page.evaluate((path) => window.__visit(path), path);
        await page.waitForURL(base + path);
        await page.getByRole("status", { name: "Loading data" }).waitFor({ state: "detached" });
        await page.getByText(item.title, { exact: true }).first().waitFor();
    }
    assert.ok(deferredRequests >= cases.length * 2);
    assert.deepEqual(errors, []);
    console.log(
        `Passed ${cases.length} routes: fresh visits, refreshes, preloaded data and Inertia navigation; no browser errors.`,
    );
} finally {
    if (browser) await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
