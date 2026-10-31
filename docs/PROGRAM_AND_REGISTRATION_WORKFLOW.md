# Programs and resident accounts

## Programs

KaBarangay stores programs as event records in `announcements`; there is no
separate `programs` table. The `program_status` column is constrained to `draft`,
`ongoing`, or `done` and is nullable for informational updates.

- Clerk, PWD, and Senior staff can save a draft or publish an ongoing program
  immediately from their Programs page. Omitted status defaults to ongoing.
- Publication never creates an Admin approval task. Program approval/rejection
  endpoints reject attempts to apply the legacy review flow to programs.
- Ongoing programs are visible to their configured resident audience. Drafts
  are unpublished and cannot accept attendance or be completed.
- Staff mark an ongoing program done through the existing completion action.
  Completed programs cannot be edited. Completion is idempotent.
- Program lists and the Admin program monitor use All/Draft/Ongoing/Done tabs.
  Legacy publication flags remain synchronized for existing resident readers;
  they do not represent an Admin approval requirement for programs.
- Existing completed events migrate to done, published events to ongoing, and
  unpublished/pending/rejected events to draft.

## Resident registration

The Resident Claim model, service, controllers, pages, and public/Admin routes
have been removed. Existing claim database tables remain historical data; they
no longer qualify a resident for the verified account directory.

Account applications and review now live at `/resident/register` and the
Pending Accounts/Needs Correction/Rejected tabs on `/admin/residents`.

Before creating a `resident_accounts` row, Admin approval locks and validates:

1. An existing active `residents` record in the application's barangay.
2. The resident's `source` is `admin`.
3. No account already exists for that resident.
4. The normalized application email matches the resident record's email.
5. The application remains pending and has not already been linked.

Approval links the application to that existing identity and creates only its
online account. It never creates a new resident. Unique account constraints and
transactional checks prevent competing applications from replacing credentials.
Admin-added residents continue receiving registration invitations.

## Deployment

Apply `2026_10_29_000004_add_program_status_to_announcements.php` with
`php artisan migrate`, clear configuration/routes, and rebuild frontend assets.
The migration also normalizes historical invitation notification types to the
new registration-invitation name.

## Verification

| Check | Result |
| --- | --- |
| `php artisan migrate` | Applied program-status migration successfully |
| `php artisan config:clear` | Passed |
| `php artisan route:clear` | Passed |
| `npm run types:check` | Passed |
| `npm run build` | Passed |
| `php artisan test --compact` | 1,245 passed, 1 skipped; 11,661 assertions; zero failures/errors |
| New PHP infrastructure formatting / `git diff --check` | Passed |

Coverage includes each staff portal's draft/publication/completion flow, rejected
approval-status input, blocked Admin program review, removed claim endpoints,
missing/non-Admin resident rejection, account-creation races, verification-token
expiry, changed-email invalidation, and idempotent registration invitations.
