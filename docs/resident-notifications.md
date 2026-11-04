# Resident updates

The resident header bell opens a small dropdown with the latest 30 updates,
unread count, and mark-all-as-read. Updates refresh every 30 seconds while the
page is visible and when the browser regains focus. Clicking an update marks it
read and opens its related page. Only the authenticated resident's records in
their barangay are accessible.

New updates are generated when a certificate request becomes `Ready for Claim`,
or staff records `present` attendance or a `claimed` aid distribution. Existing
historical events are not backfilled. Attendance saved offline generates its
update after staff successfully syncs the record to the server.

Inbox records use `resident_notifications`; the existing registration and Lupon
email outbox remains separate. A unique event key prevents repeated saves from
creating duplicate inbox records or dispatching extra email jobs.

## Enable on deployment

1. Back up the production database before deployment. The production Docker startup runs `php artisan migrate --force` before starting the web server and worker. On other hosts, run that command before serving this release. Never use `migrate:fresh`, `migrate:reset`, `db:wipe`, or production seeders. The new migrations add an inbox table and insert a missing catalog row while preserving existing fees and settings.
2. Configure a working mail transport and sender. The `log` mailer does not
   deliver email. Email updates go only to approved residents with a verified
   email address.
3. Keep `QUEUE_CONNECTION=database` (or another asynchronous queue) and run a
   supervised `php artisan queue:work --queue=resident-updates --tries=3`. The
   production Docker image starts this worker together with Apache and restarts
   it if it exits. Rebuild and redeploy that image to activate the hosted worker.
   Jobs are dispatched after the
   source transaction commits. Email failure does not remove the inbox update.
4. Check failed jobs with `php artisan queue:failed`; investigate provider errors
   before retrying. A retry after an ambiguous provider timeout may send twice.
5. Test with a consenting test account: issue a free certificate or confirm
   payment through the existing workflow, record attendance, open the bell, and
   verify arrival at the account's verified email. Do not bulk-send test updates
   to real residents. Automated tests fake delivery and cannot establish actual
   inbox delivery.

Focused checks: `tests/Feature/ResidentNotificationTest.php` and
`node tests/frontend/notifications.browser.test.mjs`.

On Windows PHP installations without a configured CA bundle, set
`GMAIL_CA_BUNDLE` to a readable PEM bundle of trusted root certificates.
HTTPS verification remains enabled. This path is machine-specific; hosted Linux
deployments should normally leave it empty and use the system trust store.
After changing mail settings, clear cached configuration and restart the worker.
