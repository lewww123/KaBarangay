# Navigation performance refactor

## System-wide infrastructure pass

- PostgreSQL enables both `PDO::ATTR_PERSISTENT` and
  `PDO::ATTR_EMULATE_PREPARES`, covered by a configuration regression test.
- `ReferenceData::remember()` centralizes five-minute `Cache::remember()` reads
  for display catalogs. Auth pages, resident registration, resident certificate
  catalogs, Clerk certificate/signatory options, Admin purok options, population,
  settings, Senior/PWD puroks, and Citizen Charter categories use this path.
  Transactional reads bypass the shared cache to avoid publishing uncommitted
  edits. Mutable resident masterlists and authorization decisions stay live.
- Reference-data invalidation now covers Certificate, OrgChartPosition,
  CitizenCharterService, and CitizenCharterVersion changes in addition to
  Barangay, Purok, and Sector changes. Keys are scoped by barangay where relevant
  and invalidated after committed saves/deletions.
- All application-defined shared Inertia props (`name`, `auth`, `flash`, and
  `certificateTypes`) are closures. Inertia's parent validation-errors prop
  retains its framework-provided lazy `AlwaysProp` contract.
- Staff portals all use the configured `web` guard and cached `users` provider;
  separate `admin`, `clerk`, or `resident` Laravel guards are not configured.
  Resident sessions use `ResidentAuth` plus the centralized request-scoped
  `ResidentContext::find()/get()` lookup. Active resident/account validation is
  performed once per HTTP request and reused by controllers.
- Local/test environments reject lazy-loaded Eloquent collection relationships.
  The complete suite exposed a Citizen Charter N+1; list/detail queries now load
  `currentVersion.uploader` explicitly. Hearing updates, case promotion, and
  resident account-status updates also explicitly preload their relationships.
  Resident directories use an ID tie-breaker to keep identical names stable when
  PostgreSQL/SQLite choose the newly available indexes.
- The live PostgreSQL catalog audit found uncovered references across 26 tables.
  `2026_10_29_000003_add_missing_foreign_key_performance_indexes.php` adds indexes
  only when a foreign key (or conventional barangay/user/resident reference) is
  not already the leading prefix of an index. It appends `status` and `created_at`
  where present, skips existing coverage, and namespaces its additions for a
  rollback that preserves pre-existing indexes. A post-migration PostgreSQL
  audit reported **zero uncovered references**.
- Executed `php artisan migrate`, `php artisan config:clear`, and
  `php artisan route:clear` successfully. The final `php artisan test --compact`
  run completed with **1,248 passed, 1 skipped, 11,702 assertions, zero failures
  or errors**. Formatting checks on the new infrastructure and `git diff --check`
  passed. The suite includes catalog cache-hit/invalidation/rollback checks and
  schema-wide leading-index coverage/idempotency/rollback checks.

## Auth lookup and resident-statistics hot-path fixes

- The `users` auth provider now uses `CachedEloquentUserProvider` for session
  identity retrieval. Raw user attributes are cached for 30 seconds and
  reconstructed as a fresh model on each lookup. Credential and remember-token
  lookups retain Laravel's normal database-backed behavior.
- `AUTH_USER_CACHE_STORE=file` avoids replacing the slow users lookup with a
  remote database-cache lookup. `AUTH_USER_CACHE_TTL=30` controls expiry; `0`
  disables identity caching. Multi-instance deployments can select a shared
  Redis store with `AUTH_USER_CACHE_STORE=redis`.
- `UserAuthCacheObserver` invalidates saved/deleted identities after commit,
  including role, status, password, and remember-token changes. Retrieval inside
  a database transaction bypasses caching so uncommitted data is not published.
  Direct SQL and bulk updates bypass model observers and are reflected at TTL
  expiry.
- The Residents controller is `Admin/ResidentRegistrationController.php`.
  Its combined aggregate result is cached for 30 seconds, keyed by barangay,
  selected purok, resident search, and registration search. Lists remain live;
  aggregate/pagination totals may lag by up to 30 seconds. Cached values are
  scalar arrays, compatible with the cache's disabled object deserialization.
- `2026_10_29_000002_add_performance_indexes_to_tables.php` adds the requested
  composites on users, residents, resident registrations, claims, and accounts.
  Rollback drops only these named indexes.
- Executed `php artisan migrate` successfully (new index migration: 5 seconds)
  and `php artisan config:clear` successfully. All 138 targeted auth, role,
  resident, cache, index, deferred-prop, and navigation tests passed. The cache
  tests verify cross-request query avoidance, expiry, committed invalidation,
  rollback isolation, and filter/barangay separation.

## Admin SQL audit and temporary query logging

The four audited GET routes are handled by controllers under
`app/Http/Controllers/Admin/` (there is no `AdminController.php`):

| Page | Controller | Relationship loading |
| --- | --- | --- |
| `/admin` | `DashboardController` | Scalar aggregates and a certificate-name subquery; no per-row relationship reads |
| `/admin/residents` | `ResidentRegistrationController` | `with()` for sectors, nested document/sector data, barangay, and decision notifications; `withExists()` for account/QR flags; batched purok hydration |
| `/admin/staff` | `StaffManagementController` | Only user columns are rendered; no relationships are needed. The paginator now selects only those columns. |
| `/admin/sectors` | `SectorController` | `withCount()` for sector totals, `with()` for certificate/resident display data, and batched purok hydration for rosters |

No per-row lazy-loading query was found in these list renderers. Batched purok
hydration deliberately reuses the already-loaded reference map and fetches any
missing IDs in one query. Adding `with('purok')` as well would duplicate that work.
`AdminRelationshipQueryBudgetTest` verifies that the full Residents and Sectors
responses use the same query count with 1 versus 15 records and distinct puroks.

`HandleInertiaRequests` shares no explicit global database query. Auth data now
uses a closure, allowing partial requests that omit `auth` to skip its resolver.
Authentication middleware still performs its required identity check. Flash data
and certificate options already use closures; `CertificateTemplates::options()`
checks local template files rather than querying the database.

Temporary query logging is enabled by default when `APP_ENV=local`. Override it
in `.env` as needed:

```dotenv
DB_QUERY_LOG_ENABLED=true
DB_QUERY_LOG_MIN_MS=0
```

Run `php artisan config:clear` after changing these settings (or rebuild the
configuration cache if the deployment uses it). Visit each audited page and let
its deferred requests finish. Every executed query on those exact GET paths,
including session/authentication queries, produces an `admin_sql_query` entry in
`storage/logs/laravel.log`. Each entry includes placeholder SQL, `duration_ms`,
connection, path, request ID, fingerprint, and Inertia partial-data header.
Bindings are omitted. The dedicated file channel writes to `laravel.log` even
when the application's default channel or level differs. Request IDs group
queries within each HTTP request; deferred requests have their own IDs.

Start at `DB_QUERY_LOG_MIN_MS=0` to see repeated queries as well as slow ones.
Set it to `100`, for example, to retain only queries taking at least 100 ms.
Compare initial and deferred responses separately. Existing
`local_page_request_diagnostics` summaries retain total request/database timing;
their fingerprints can be matched to the new SQL entries.

Potential latency sources to inspect in those logs include the combined count
queries, sector schema-table discovery, cold reference-data cache reads, and
session/authentication reads. Fixture tests do not establish which query is
slowest on the deployed database; the new timings identify that after a visit.
Disable temporary SQL logging after profiling with `DB_QUERY_LOG_ENABLED=false`.

## Behavior

- `INERTIA_DEFER_HEAVY_PROPS=true` enables the shared `deferProp()` strategy.
  Page shells arrive before table queries, aggregates, and related detail data.
  React `Deferred` boundaries keep data-dependent hooks/forms unmounted until
  their required props arrive and display accessible skeletons while loading.
- Navigation links in all six portal layouts prefetch on hover, with a 10-second
  client cache. They do not prefetch every sidebar destination on page mount.
- Filters and pagination use partial requests where applicable. The composite
  Admin Residents and Sectors resolvers now skip unrequested sibling lists,
  rather than computing every table and discarding most of the response.
- Resident authentication combines the active resident/account checks in one
  query. `ResidentContext` reuses that result only within the current request.
  Dashboard, Profile, Certificates, and Lupon defer their related display data.
  Revocation/account status is still checked on the next HTTP request.
- Population totals, sector overlap, and per-purok totals use a grouped resident
  query with existence subqueries. The registration pipeline uses one grouped
  count. Clerk/Senior/PWD dashboard metrics use conditional counts and a scoped
  resident-count subquery in one metrics statement.
- Reference-data caches contain display arrays/scalars, not authenticated
  identities or credentials. Barangay/purok/sector options and the settings
  barangay name have a five-minute TTL. `ReferenceDataObserver` invalidates the
  corresponding reference keys after committed model saves/deletions.
- PostgreSQL already has `PDO::ATTR_PERSISTENT => true`; it remains enabled and
  is covered by a configuration regression test.

## Controllers changed in this pass

Under `app/Http/Controllers/Admin/`:

- `AnnouncementApprovalController.php`
- `AuditLogController.php`
- `CertificateRequestController.php`
- `EmergencyContactController.php`
- `PopulationController.php`
- `ResidentClaimController.php`
- `ResidentRegistrationController.php`
- `SectorController.php`
- `SectorApplicationController.php`
- `SettingsController.php`
- `StaffManagementController.php`

Under `app/Http/Controllers/Resident/`:

- `AnnouncementController.php`
- `CertificateRequestController.php`
- `CitizenCharterController.php`
- `DisbursementDocumentController.php`
- `LuponCaseController.php`
- `LuponComplaintController.php`
- `OrgChartController.php`
- `RegistrationController.php`
- `ResidentRegistrationAccessController.php`
- `SectorApplicationController.php`

Shared/root controllers:

- `app/Http/Controllers/Controller.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/ClerkController.php`
- `app/Http/Controllers/SeniorController.php`
- `app/Http/Controllers/PwdController.php`

Resident Dashboard/Profile route closures in `routes/web.php` also use deferred
props and the request-local resident context.

## React pages and layouts changed in this pass

Under `resources/js/pages/Admin/`:

- `AnnouncementApprovals.tsx`
- `AuditLog.tsx`
- `Certificates.tsx`
- `EmergencyNumbers/Index.tsx`
- `Population.tsx`
- `ResidentClaims.tsx`
- `Residents.tsx`
- `SectorApplications/Index.tsx`
- `SectorApplications/Show.tsx`
- `Sectors.tsx`
- `Staff.tsx`

Other pages:

- `resources/js/pages/Auth/SelectBarangay.tsx`
- `resources/js/pages/Resident/Dashboard.tsx`
- `resources/js/pages/Resident/Certificates.tsx`
- `resources/js/pages/Resident/Lupon.tsx`
- `resources/js/pages/Resident/Profile.tsx`

Hover prefetch is configured in `AdminLayout.tsx`, `ClerkLayout.tsx`,
`LuponLayout.tsx`, `SeniorLayout.tsx`, `PwdLayout.tsx`, and `ResidentLayout.tsx`
under `resources/js/layouts/`.

## Shared infrastructure and workflow validation

- Added `app/Support/ResidentContext.php` and updated `ResidentAuth` middleware.
- Added `app/Observers/ReferenceDataObserver.php`, registered on `Barangay`,
  `Purok`, and `Sector` models.
- Fixed QR credential resolution to select the token needed for byte-exact
  comparison, then remove it from the returned credential attributes.
- Matched the Indigency merge-field allowlist to the provisioned DOCX's purpose
  field. Source DOCX templates were not rewritten.
- Made secure registration page targets explicit for component integrity checks.
- Updated stale regression fixtures/assertions for explicit resident linking,
  pending-only approval, current session APIs, download headers, and declared
  DOCX merge fields. Invitation tests use real commits. Correction upload tests
  use actual PNG bytes without requiring GD just to generate their fixtures.
- Set PHPUnit's memory limit to 512 MB for the complete regression suite.

## Verification

| Check | Result |
| --- | --- |
| `npm run types:check` | Passed |
| `npm run build` | Passed |
| `php artisan test` | 1,231 passed, 1 skipped; 11,467 assertions; zero failures/errors |
| Production-bundle browser smoke checks | 41 fixture-backed page renders passed; no captured JavaScript exceptions |
| `git diff --check` | Passed |

`tests/Feature/NavigationPerformanceTest.php` enforces these query budgets:

- Eight Admin page shells execute zero business-data queries before deferred
  loading, in an already-authenticated test context.
- Seven Resident page shells execute one combined resident/account validation
  query before deferred loading. Deactivating the account blocks the next visit.

The tests run against isolated SQLite fixtures. Browser checks use the actual
production JavaScript bundles and simulated Inertia deferred responses. These
are correctness/query-budget checks, not a live AWS Supabase benchmark.

## Sub-second navigation measurement

The implementation removes blocking heavy reads from the audited page shells;
it does not establish a universal deployed latency guarantee. Measure both:

1. Click-to-page-shell paint, including document/JS delivery, authentication,
   session middleware, and the initial Inertia response.
2. Click-to-loaded-data, including deferred responses and database execution.

On the deployed application, record cold and warm visits, hover-prefetched and
unprefetched visits, and p50/p95 across realistic resident/table sizes. Compare
the initial response and deferred requests separately in browser Network tools
and correlate with database query timing. A shell appearing below one second
does not mean its deferred data has finished loading below one second.
