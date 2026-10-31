# KaBarangay — Project Progress & Acceptance Report

Last updated: 2026-10-16 (P0-2 Batch 2 acceptance + P0-2 final readiness audit, read-only)

> This file was created because no existing progress report/checklist artifact was found in the
> repository or workspace. It is a documentation artifact only; no source, tests, migrations,
> seeders, schema, or configuration were modified.

## Status legend
- COMPLETE — verified by evidence (tests/inspection)
- OPEN — needs a decision or action
- BLOCKED — cannot be verified/completed safely in this task's constraints
- OPTIONAL — non-blocking follow-up

---

## P0-2 Batch 2 — Announcement Feed & Authorization Fixes — ACCEPTED ✅

Recorded as accepted on 2026-10-16 based on the read-only review.

| Item | Status | Evidence |
|---|---|---|
| Senior resident feed targets canonical `senior` slug | COMPLETE | `Resident/AnnouncementController.php`; `routes/web.php` resident closure; `AnnouncementManagementTest` targeting tests |
| Clerk may CRUD `all`/`senior_citizens`/`pwd` in own barangay | COMPLETE | `Clerk/AnnouncementController.php`; `test_clerk_can_create_announcements_for_every_allowed_audience`, `..._created_by_another_clerk` |
| Cross-barangay update/delete/attendance denied, no mutation | COMPLETE | `AnnouncementManagementTest` :124/:148/:164; `BarangayIsolationTest` |
| Sector staff forced to own audience; other-audience denied | COMPLETE | `Senior|Pwd` controllers; `AnnouncementManagementTest` :259/:278/:297 |
| Edit/resubmit unpublishes and requires Admin re-approval | COMPLETE | `update()` sets `is_published=false` + `pending`; `test_editing_approved_..._requires_reapproval` |
| Rejection feedback retained + shown after resubmission (pending) | COMPLETE | `{Clerk,Senior,Pwd}/Announcements/Index.tsx`; `test_pending_announcement_after_resubmission_retains_and_exposes_rejection_reason` |
| Residents never see rejection feedback | COMPLETE | resident feed filters `approved`+`published`; approve clears reason |
| Audit log for staff submit/resubmit (`created`/`resubmitted`) | COMPLETE | `Concerns/RecordsAnnouncementApprovalActivity.php`; `test_staff_submission_and_resubmission_are_written_to_the_approval_log` |
| Regression suite | COMPLETE | `tests/Feature/AnnouncementManagementTest.php` (20 tests) |

Batch 2 result snapshot: **111 tests / 573 assertions passed**, `tsc --noEmit` clean, targeted Pint clean.

---

## P0-2 Final Readiness Audit

### P0-1 security regressions (relevant to P0-2)
| Item | Status | Evidence |
|---|---|---|
| Barangay isolation (dashboards, feed, latest, QR, registration approval, attendance) | COMPLETE | `tests/Feature/BarangayIsolationTest.php` (11 tests) |
| Role authorization (portals, approvals, certificates, QR API, inactive/super_admin) | COMPLETE | `tests/Feature/RoleAuthorizationTest.php` (18 tests) |
| Standalone P0-1 report artifact | OPTIONAL | None found; status inferred from passing suites |

### P0-2 Batch 1 — tracked-secret containment
| Item | Status | Evidence |
|---|---|---|
| `.gitignore` ignores `.env.save` and `*.dump` | COMPLETE | `.gitignore:14, .env.save, *.dump` |
| `.env.save` removed from git tracking | **OPEN / BLOCKED** | Still tracked: `git ls-files` lists `.env.save`; committed in `4cb0180` |
| `kabarangay_backup.dump` removed from git tracking | **OPEN / BLOCKED** | Still tracked: `git ls-files` lists `kabarangay_backup.dump`; committed in `4cb0180` |
| Secret purge from git history + credential rotation | **OPEN** | Requires history rewrite + rotation decision; out of scope here |

Risk: `.env`-style and DB-dump artifacts in a public/shared repo can expose APP_KEY, DB credentials, and resident PII. `.gitignore` does not untrack already-committed files.

### Password-reset email security
| Item | Status | Evidence |
|---|---|---|
| Random 64-char token, stored hashed (bcrypt) | COMPLETE | `Resident/PasswordResetController.php:55-67` |
| 60-minute expiry enforced | COMPLETE | `:25, :149-153`; `test_expired_token_is_rejected_and_removed` |
| Single-use, atomic consumption | COMPLETE | `:171-177`; `test_reset_token_cannot_be_reused` |
| No account enumeration (uniform response) | COMPLETE | `:96-103`; `test_unknown_email_sends_no_mail_and_leaks_nothing` |
| Mail failure removes token, logs only exception class (no token/URL) | COMPLETE | `:77-93`; `test_delivery_failure_does_not_log_the_reset_url_or_token` |
| Multi-barangay shared email → distinct tokens | COMPLETE | `test_shared_email_across_barangays_issues_distinct_tokens` |

### Certificate-template availability gating
| Item | Status | Evidence |
|---|---|---|
| Backend rejects unsupported/unavailable types | COMPLETE | `CertificateController.php:16-27, 46-50`; `RoleAuthorizationTest`, `CertificateGenerationTest` |
| UI disables unavailable types ("Coming Soon") | COMPLETE | `Clerk/Certificates.tsx:254-259` |
| All templates provisioned | **OPEN** | Only `tricycle.docx` present; 6 of 7 types unavailable (`storage/app/templates`) |

### Existing test / TypeScript / build / manifest / formatting
| Item | Status | Evidence |
|---|---|---|
| PHPUnit suite | COMPLETE | 111 passed / 573 assertions (files unchanged since run) |
| TypeScript (`tsc --noEmit`) | COMPLETE | clean |
| Vite build / manifest | COMPLETE | `public/build/manifest.json` present (`/public/build` gitignored) |
| Pint (targeted changed file) | COMPLETE | passed |
| Pint (repo-wide) | **OPEN** | `vendor/bin/pint --test` fails on ~44 files (pre-existing) |
| JS check (`vp check`) | **OPEN** | fails: formatting issues in 48 files |

### Outstanding environment / tooling decisions
| Item | Status | Evidence / decision needed |
|---|---|---|
| APP_KEY exposure / rotation | **OPEN** | `.env.save` tracked; rotate APP_KEY + any DB/mail credentials after untracking/purge |
| Mail-provider configuration | **OPEN** | `config/mail.php` default `log`; `.env.example MAIL_MAILER=log`; real provider + secrets required for production |
| Certificate-template provisioning | **OPEN** | Add approved `.docx` templates for the remaining 6 types |
| PHPStan / Larastan incompatibility | **OPEN** | Larastan v3.10.0 + Laravel v13.29.0 → `phpstan analyse` reports 190 errors (incl. false-positive `larastan.relationExistence`); do not fix in P0-2 |
| Pre-existing Pint failures | **OPEN** | ~44 files; CI remediation only |
| Legacy `resources/js/pages/Clerk/Announcements.tsx` | OPTIONAL | Unreferenced/dead code; delete as housekeeping (not in this task) |

---

## Acceptance decisions
1. **Batch 2 acceptance: RECORDED** (this file).
2. **P0-2 overall: NOT ready for final acceptance** while the blockers below remain.
3. **Remaining blockers before P0-2 can close:**
   - Tracked secrets: `.env.save` and `kabarangay_backup.dump` still tracked and in history.
   - Secret rotation decision (APP_KEY and any DB/mail credentials).
   - CI red: PHPStan (190) + Pint (~44 files) + `vp check` (48 files).
   - Mail-provider configuration.
   - Certificate-template provisioning.
4. **Recommended next task (not executed):** P0-2 Batch 3 — Secret containment & credential rotation
   (untrack `.env.save`/`kabarangay_backup.dump`, purge from history, rotate exposed secrets), followed by
   a CI/tooling remediation batch.
