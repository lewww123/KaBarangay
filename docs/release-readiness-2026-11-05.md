# Release checks — November 5, 2026

These fixes are local and have not been pushed or deployed.

Final runtime suite: 1,388 tests, 1,371 passed, 17 skipped, zero failures and
zero errors. The skipped tests are not counted as passing coverage.

## Resolved test issues

- Resident statistics assertions now cover current counts, including a resident
  added between requests, rather than expecting a stale aggregate cache.
- Registration catalogs remain present on initial loads and validation redirects
  so the browser wizard keeps its data and files.
- Lupon settlement tests use the workflow's Manila calendar date.
- Source contract assertions tolerate formatter line breaks, trailing commas,
  and JSX whitespace without removing the behavior assertions.
- Project formatting and frontend lint warnings were corrected.

## Database inspection

The database configured by this checkout was inspected through a PostgreSQL
`READ ONLY` transaction. No records, schema, or migration history were changed.

- Notifications have the required columns and unique event key index.
- Certificate catalog entries have the required barangay/code unique index.
- No duplicate key groups were found in either table.
- The Sicsican Business Clearance migration is pending, and no matching catalog
  entry exists yet. This is an unapplied additive migration, not a key conflict.
- The notifications migration now accepts a compatible existing table, stops on
  an incompatible one without modifying its records, and retains history on
  rollback. Isolated SQLite tests cover preservation and conflicting schemas.

Take a verified database backup before the eventual production migration.

## Remaining release limitation

The PHP static checker now starts with the proper Larastan bootstrap and a 1 GB
memory limit. It reports 1,286 diagnostics across the existing project. These
have not been suppressed, baselined, or treated as passing. Consequently the
complete `composer ci:check` pipeline is not yet green, even when runtime tests
pass. This is not a full release approval.

The production build, TypeScript check, frontend lint, and the notification,
pending-account, registration-retry, and staff browser checks passed locally.
