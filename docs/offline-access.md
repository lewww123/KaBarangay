# Same-device offline access

Production builds register `/service-worker.js` over HTTPS (or localhost). The
Vite development server intentionally does not install it. The certificate
queue uses native IndexedDB and requires the additive migration
`2026_11_04_000001_add_offline_identity_to_certificate_requests.php`. Run this
migration before enabling the new deployment. No local server is needed.

Sign in online, open a page, and wait for **Saved for offline**. Fully loaded
authenticated Inertia pages are saved as sanitized, static HTML copies. The
last 20 page URLs, including their selected query filters, are retained for up
to 24 hours. Deferred data must finish loading before saving. Saved copies use
the CSS/fonts already used by the page, and do not start React or make live API
requests. Browser eviction, private browsing, quota limits, or clearing site
data can remove copies. Unsaved pages require internet.

## Offline use

- Reload the previously saved URL, or open `/offline.html` to list saved pages.
- Read the saved information and timestamp. This is not current database state.
- Live filters/tabs, new document downloads, email delivery and AI responses
  require internet. Payment, official receipts, certificate approval and
  release, and Lupon actions require the online process.
- A page still open when connectivity is lost blocks Inertia writes and form
  submissions. Offline navigation opens a static saved page. Background Inertia
  polling is canceled while offline.

## Offline certificate requests

Sign in as a verified resident online and wait for **Offline certificate drafts
are ready**. Open **Offline drafts & requests** (`/offline-certificates.html`).
The normal certificate form also has **Save draft on this device**. In the
offline editor, save an incomplete draft or review the details and confirm
queueing. **Pending sync is not an official submission** and has only a local
UUID. After server acceptance, the editor shows the official reference, fee,
and actual status. Synced does not mean approved.

Sync runs on reconnection, every 30 seconds while a prepared page is open, and
through **Sync now**. Supported browsers can also use service-worker Background
Sync. Closed-browser background delivery is not guaranteed; reopen the site
and use Sync now if needed. Network errors retain the same UUID even if the
server might already have accepted the request. A server transaction, resident
row lock, payload hash and unique resident/UUID index prevent repeat creation.
Concurrent tabs share a local sync lease. Queued requests cannot be edited or
deleted until their submission result is known.

The server rechecks the signed-in resident, barangay, active certificate, form
validation and catalog revision. Changed fees, requirements or fields produce
**Needs review** without creating a request. Refresh the catalog and explicitly
review and confirm again. Expired login requires online sign-in to the same
resident account. A different account cannot submit the original queue.

Catalogs, form data and server receipts are encrypted with AES-GCM in IndexedDB.
Each resident has a separate server-derived key; active browser storage retains
that key while offline editing is unlocked. This protects locked drafts after
logout, not a compromised or shared unlocked browser profile. Logout/account
switch deletes the active key and page snapshots; encrypted drafts remain so
the same resident can unlock them after online sign-in. Keep APP_KEY stable:
rotating it makes existing local drafts unreadable. No password, access token
or CSRF token is stored in the queue. The current CSRF token is fetched at sync.
Up to 50 unsent requests are allowed per account. Clearing browser site data,
storage eviction or losing the device permanently loses unsynced drafts.

## Boundaries

Offline copies contain the information visible on the saved page, so they are
private browser storage and must be treated as resident/staff data. Copies are
scoped by a server HMAC of the authenticated identities and session ID. A
different account/session clears the previous copies. Expired copies are not
served. Do not share the signed-in browser profile.

Snapshots exclude scripts/bootstrap data, CSRF metadata, all form controls and
form content, transient dialogs/alerts, private images/documents, embedded document previews and AI chat.
Only the offline viewer script executes; a restrictive CSP blocks form actions
and inline scripts. Only static build CSS, fonts and public icons are cached as
resources. Private document responses, login pages, reset links and registration
tracking pages are not saved.

Online and offline logout clear every saved private page. If logout occurs
without internet, a pending logout marker prevents reuse of saved information.
The service worker completes a CSRF-protected server logout before allowing
future online app requests. It fetches the current CSRF token transiently and
never persists it. A logout invalidates in-flight identity responses to avoid
restoring an old scope from an earlier request.

No offline copy can provide a fresh server authorization check while the server
is unreachable. The 24-hour boundary and explicit logout are intentional local
access limits; offline mode does not grant new permissions or authenticate a new
user.

## Checks

Use a production build and a browser with service workers enabled. Load a page,
wait until saved, then disable the network and reload. Test another saved URL,
an unsaved URL, form/write blocking, private file exclusion, logout, account
switch, pending-logout completion and cache expiration. Confirm deferred data
appears in the saved copy. The certificate browser test additionally checks
encrypted draft persistence, fee conflicts, review, response-loss retries,
concurrent sync, account isolation and key removal on logout. Browser tests use
simulated server responses and do not alter real barangay data or send email.

Reference: https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API/Using_Service_Workers
