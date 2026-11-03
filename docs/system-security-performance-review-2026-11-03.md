# KaBarangay system review — 3 November 2026

## Scope and limits

Repository review of authentication, department authorization, barangay isolation,
private document delivery, registration/password reset, administrative exports,
page data loading, database indexes, dependency advisories, and test coverage.
Checks run locally; automated tests use an isolated SQLite database. This is not
a production penetration test or a PostgreSQL load benchmark. A passing test
suite and clean dependency audits do not establish that every page is fast or
that the system has no vulnerabilities.

Route inventory: 264 registered routes (Admin 52, Clerk 55, Lupon 29, PWD 28,
Resident 54, Senior 28, shared/public 18). This inventory is not a claim of
manually exercising every route in a browser.

## Changes prepared

| Area | Change and reason |
| --- | --- |
| Staff RBAC | Active assignments must match both the user's barangay and linked resident. Barangay, resident, resident account, and live database user must be active. Invalid or missing identity context returns 403. |
| Revocation | Role checks validate the database user even when authentication returns a cached identity. Bulk deactivation cannot continue to authorize the stale cached user. |
| Staff assignment eligibility | Search and creation reject inactive login accounts and inactive linked users. Existing custom department labels remain supported; portal access still requires a recognized department. |
| Staff login | Filter by user name and matching user/assignment resident ID in SQL before loading candidates and their relationships. Passwords remain hashed; database filtering does not replace credential verification. |
| Browser protection | Add nosniff, SAMEORIGIN framing, and same-origin referrer policy. Authenticated responses use private/no-store cache control. HTTPS APP_URL defaults to secure session cookies unless explicitly overridden. |
| Development server | Restrict CORS to the application origin and local development origins. |
| Admin sector queue | Replace the unlimited application/document collection with 25 rows per page, stable ordering, validated server search/status/sector filters, and aggregate counts. Documents load on details rather than on every list row. Search applies across all pages. |
| Indexes | Add barangay/status/created_at/id coverage for the filtered sector queue and barangay/created_at coverage for staff listing. Reuse the existing unfiltered sector queue index. |
| Dependencies | Update Laravel, CommonMark, relevant Symfony packages, and frontend build dependencies. Use the official Vite+ upgrade layout with npm aliases to preserve npm-based Docker/CI installation. |
| Tests | Add adversarial identity/revocation and queue-bounds/isolation tests. Isolate test sender settings from local .env. Update stale purok and Lupon test assumptions and distinguish necessary authorization SQL from report-data SQL. |
| CI | Add PHP and JavaScript dependency security audits, including execution when preceding CI checks fail. |

## Outstanding findings, ordered by priority

1. **High: historical secret/database artifacts.** Git history still contains
   commits for `.env.save` and `kabarangay_backup.dump` (including `4cb0180` and
   removal commit `df64d34`). Current tracking excludes them. Treat credentials
   and data that existed in those artifacts as potentially exposed. Inventory
   and rotate affected credentials; coordinate history cleanup with repository
   collaborators. Do not blindly rotate APP_KEY: encrypted document backups need
   a key migration/re-encryption plan, and existing sessions will be invalidated.
2. **High: test accounts in the shared database.** The requested dummy staff
   include administrator access with a shared, disclosed test password. Restrict
   them to testing, rotate their passwords, or deactivate them before opening the
   system to real users. Do not run dummy seeders as part of production deploys.
3. **High: static analysis is not clean.** The repository-wide PHPStan check
   reports 1,253 issues after the framework update (including relationship return
   typing, date properties, and controller signatures). These need triage; the
   count is not a count of confirmed exploitable bugs. No baseline or ignore
   rules were added to conceal them. CI currently invokes static analysis and
   can remain red even if runtime tests pass.
4. **Medium: remaining unlimited collections.** Senior/PWD resident directories
   and application queues, administrative announcement approvals, and some
   exports still materialize entire collections. Paginate interactive queues
   with matching UI/server search; stream large exports. Do not silently cap
   arrays without allowing users to reach omitted records.
5. **Medium: upload persistence coverage.** Registration document backups do not
   establish durable storage for every other upload family (sector documents,
   Lupon evidence/generated files, branding, org-chart photos). Verify each disk
   and restore path across a replacement deployment. Lost historical files
   require restoration or applicant resubmission.
6. **Medium: background email reliability.** Password-reset jobs default to a
   separate background process, which is not a durable delivery queue. Verify
   actual delivery, failure monitoring, and retries. A database queue needs a
   running worker; selecting database alone does not send mail. Keep credentials
   and reset links out of logs. Provider acceptance is not inbox confirmation.
7. **Review required: Lupon scheduling override.** `hearing_actions_unlocked`
   defaults to true as a temporary review override. Decide whether production
   should enforce scheduled-time restrictions. Tests now explicitly select
   locked mode when checking those rules; product behavior was not changed.
8. **Production performance remains unmeasured.** Validate PostgreSQL plans and
   index use with representative data after migration. Measure cold/warm initial
   and deferred requests separately, plus p50/p95, response bytes, memory, and
   SQL time. The free Render instance and remote database connection are separate
   contributors to latency that query changes alone cannot eliminate.

## Validation

Initial full run: 1,321 tests; 1,297 passed, 17 skipped, 3 failures and 4 errors.
Corrected baseline assumptions and isolated the test sender configuration.
Targeted RBAC, cache, reports, exports, Lupon list, queue and security tests pass.
Final full run: **1,326 tests, 1,309 passed, 17 skipped, zero failures/errors**;
12,348 assertions, approximately 256 seconds on this workstation. Skips remain
unverified coverage, not successful checks.

Production frontend build: passed, 1,027 modules transformed, approximately
43 seconds. TypeScript `tsc --noEmit`: passed. Changed PHP files formatted;
`git diff --check`: passed. No production latency or speedup percentage measured.

PHP dependency audit after updates: zero advisories, no abandoned packages.
Clean npm installation after the toolchain upgrade: zero vulnerabilities reported.
PHPStan: failed, 1,253 issues, using a 1 GB limit after the default 128 MB failed.

## Deployment and acceptance

These changes are local and have not been published by this review. Before
release: review the diff, arrange a database backup, install locked dependencies,
build assets, and run migrations including
`2026_11_03_000002_add_staff_and_sector_queue_indexes.php`. This migration adds
indexes, not replacement data; rollback removes only its two indexes. On large
PostgreSQL tables, schedule index creation to avoid disruptive write blocking.
Deploy PHP and frontend together and refresh configuration/routes/views through
the existing startup procedure. Verify each role with its own barangay, then
verify cross-barangay rejection and a role/account revocation on an existing
session. Confirm production debug is disabled and cookies are secure over HTTPS.

Existing performance tests cover deferred navigation, partial-prop queries,
relationship query budgets, reference caching, and index coverage. Keep these
checks separate from production response-time claims.

Toolchain upgrade reference: https://viteplus.dev/guide/upgrade-project
