# Attendance verification and offline capture

## Online attendance

Clerk, PWD and Senior attendance sheets support manual marking and QR credential
resolution. Attendance must be enabled and the update/program approved; a draft
program is not eligible. Each portal enforces the resident's barangay and, where
applicable, sector. The online QR flow requires a short-lived single-use server
proof. It is not usable offline and was not weakened to implement offline mode.

The existing unique `(announcement_id, resident_id)` record prevents duplicates.
An existing Present record cannot currently be converted to Claimed: that would
overwrite its original metadata. This is an existing product limitation, not a
successful aid-claim workflow. A separate claim record would be required to
track both attendance and subsequent aid distribution independently.

## Offline use

1. Sign in online as Clerk, PWD or Senior, open an eligible attendance sheet, and
   click **Prepare / refresh roster**. Wait for the ready message. A production
   HTTPS build with the service worker is required; the Vite development server
   does not install offline support.
2. Open **Offline attendance & sync** before or after losing connectivity. The
   prepared roster is valid for recording for 24 hours. Search the roster for
   manual attendance, or focus the QR field and scan with a keyboard-style USB
   or Bluetooth scanner (Enter submits). Camera decoding is available only on
   browsers providing BarcodeDetector with QR support, subject to camera
   permission. Unsupported browsers still support keyboard scanners and manual
   entry. Physical camera/scanner hardware has not been tested by the automated
   checks; browser tests simulate scanner keyboard input.
3. Local records display **pending**. Keep this browser's site data. The page
   attempts foreground sync on reconnect/every 30 seconds while open, and offers
   **Sync now**. Reopen this page to continue after closing the browser. There is
   no promise of background synchronization while the page/browser is closed.
4. Review each result: recorded, already present, already claimed, conflict, or
   rejected. An HTTP success with a duplicate/conflict result is not a newly
   inserted attendance. Permission/eligibility errors do not become successful
   attendance. Unknown responses, network failures, throttling and server errors
   leave the same request pending for retry.

If the session changes, sign in as the same staff account and prepare the sheet
online again to unlock its encrypted local entries. If a roster/grant expired,
review rejected entries and refresh the roster before entering a new reviewed
attempt. The server prevents any duplicate attendance from that new attempt.

## Integrity and privacy

- Roster and outbox payloads are encrypted in IndexedDB. Only IDs, owner hashes
  and queue states remain plaintext in the metadata. Active keys are available
  to this site's scripts while unlocked; this does not protect against a
  compromised device or same-origin script execution.
- Prepared rosters contain names, resident numbers and QR hashes, not raw QR
  credentials. A scanned token is encrypted only inside its pending request.
- Switching session/account or logging out locks the queue and removes its
  active key. Another staff account cannot list or sync someone else's entries.
  This preserves encrypted pending work for the original account to recover.
- Offline capture is provisional. Server authorization cannot be refreshed
  without internet; sync rechecks staff access, event state, resident eligibility,
  and whether the exact QR credential is still active/unrevoked. The client roster
  is never the authority for a database insert.
- A grant is bound to the staff owner, department, and event. The sync endpoint
  cannot reuse another person's grant or submit a different resident as a valid
  QR without that resident's active credential.
- Every local attempt has a UUID. The server stores a payload hash and receipt,
  under a per-staff unique index, in the same transaction as attendance. Replays
  return the original result; altered replays return 409. Per-user/event locks
  and the existing attendance unique index protect concurrent submissions.
- `attended_at` remains the server verification time. The device-reported
  `captured_at` is retained separately in the sync receipt and is not treated as
  trusted proof that the event occurred at that time. Successful sync attempts
  also write an allowlisted audit event without QR/session credentials.

Up to 10,000 eligible roster entries may be prepared; larger rosters return an
explicit error rather than silently omitting residents. Up to 500 unsynced local
entries can be retained before sync is required. Device quota/eviction/clearing
site data can still lose unsynced work; local storage is not a server backup.

## Integration and deployment

This feature extends the offline work already in progress: `OfflineIdentity`,
`OfflineAccessController`, `OfflineSupport`, snapshots and the shared service
worker. It adds a dedicated interactive shell instead of executing forms inside
sanitized read-only snapshots. The service worker precaches the attendance shell
and scripts, preserves snapshot links to it, and locks both outboxes on logout.
Non-fingerprinted attendance scripts use revalidation headers.

Deploy with the reviewed offline base and run
`2026_11_04_000002_create_offline_attendance_submissions_table.php` before using
sync. Build PHP and frontend assets together. No production resident/event data
was created or marked by the automated verification; it uses isolated database
fixtures and a local browser test server.

Verification commands:

```text
php artisan test --filter='OfflineAttendanceTest|AttendanceRecordingTest|AnnouncementAttendanceScanFlowTest|AnnouncementAttendanceDuplicateHandlingTest|AnnouncementAttendanceVisibilityTest|QrAttendanceProofTest|ProgramPublicationWorkflowTest|OfflineAccessTest'
node tests/frontend/offline-attendance.browser.test.mjs
node tests/frontend/offline.browser.test.mjs
npm run types:check
npm run build
```

Verification completed locally: 113 attendance/offline-related PHP tests passed,
both attendance and existing read-only offline browser tests passed, the
certificate offline browser regression passed, TypeScript passed, and the
production frontend build passed. This is not confirmation of production
deployment or physical scanner/camera compatibility.

The browser test covers offline manual and keyboard-QR entry, duplicate/invalid
scans, encrypted storage, reconnect, lost-acknowledgement retry, staff-account
isolation and logout locking. PHP tests revalidate revoked credentials, role,
barangay/sector, event status, expiry, altered replays and online/offline races.
