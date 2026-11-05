# Resident registration: deferred mobile verification

Registration requires a valid Philippine mobile number, password, personal
information and the existing identity documents. Email is optional. Blank email
is stored as null. No registration email/SMS OTP is sent or required.

Submission creates a pending application. Only the existing Admin review can
approve and activate the account. Resident matching, Resident IDs, QR credentials,
application reference/password access, throttling and document rules are unchanged.

## Verification

The additive migration `2026_11_06_000001_add_mobile_verification_timestamps.php`
adds nullable `mobile_verified_at` to `resident_registrations` and `residents`.
Existing rows and new applications remain null. These timestamps are not mass
assignable from client input. Changing a stored mobile number clears its timestamp;
approval does not set it. No migration was run against production or Supabase.

Before a later approved deployment, verify the target environment/database and
inspect `information_schema.columns` for equivalent mobile-verification columns.
If the schema matches the repository, the separately approved target-environment
command is:

```sh
php artisan migrate --path=database/migrations/2026_11_06_000001_add_mobile_verification_timestamps.php --force
```

Run this only in the intended, reviewed environment after a backup and approval.
The rollback intentionally retains verification metadata. Historical registration
email-verification tables, models/relationships and records are preserved. The
registration-specific OTP routes, mailers, UI and services are removed; unrelated
resident email verification and password recovery are retained.

## Notifications

Valid supplied email may receive the existing approval, rejection or correction
notice without email verification. No-email decisions use the existing deferred
outbox convention with `no_email_supplied`; no mail is sent and no successful
delivery is fabricated. Approval email embeds the approved resident's actual QR
credential. Existing delivery leases, deduplication, retry authorization and
after-commit handling remain in effect. Other email jobs keep their eligibility rules.

## Remaining limitations

- No SMS provider, OTP, notification delivery or SMS recovery is configured.
- Mobile is an existing login identifier, not proof of number ownership.
- Existing Resident ID, mobile and email login still require the existing password,
  barangay and active/approved-account checks.
- Password recovery remains email-based. Residents without email cannot use that
  self-service reset flow; no new recovery process is introduced here.
- An optional unverified email can be mistyped or owned by another person. The
  existing email-reset eligibility is unchanged; supplying an address does not
  establish its ownership or populate `email_verified_at`.
- Approval preserves the matched masterlist mobile. A mismatch with the application
  needs explicit Admin review; this change does not silently overwrite it.
- Future SMS verification/provider integration requires separate approval. It should
  keep provider-independent transport, real delivery outcomes, safe retries and
  hashed/session-bound verification proof. Do not retrofit fake verification into
  legacy rows.

## Implementation file manifest

Paths are relative to the KaBarangay repository root.

### Created

- database/migrations/2026_11_06_000001_add_mobile_verification_timestamps.php
- docs/resident-registration-deferred-mobile.md

### Modified

- .env.example
- app/Http/Controllers/Admin/ResidentRegistrationController.php
- app/Http/Controllers/Resident/RegistrationController.php
- app/Http/Controllers/Resident/ResidentRegistrationAccessController.php
- app/Http/Controllers/Resident/ResidentRegistrationCorrectionController.php
- app/Http/Requests/Resident/StoreResidentRegistrationRequest.php
- app/Models/Resident.php
- app/Models/ResidentRegistration.php
- app/Providers/AppServiceProvider.php
- app/Support/AssistantSystemKnowledge.php
- app/Support/ResidentRegistrationDecisionNotificationService.php
- app/Support/ResidentRegistrationDuplicateChecker.php
- resources/js/pages/Resident/Register.tsx
- resources/js/pages/Resident/RegistrationApplication.tsx
- routes/web.php
- tests/Concerns/CreatesBarangayFixtures.php
- tests/Feature/AdminResidentCreationTest.php
- tests/Feature/RegistrationEmailOtpTest.php
- tests/Feature/ResidentCertificateAuditEventsTest.php
- tests/Feature/ResidentRegistrationCorrectionTest.php
- tests/Feature/ResidentRegistrationDecisionNotificationTest.php
- tests/Feature/ResidentRegistrationVerificationTest.php
- tests/frontend/registrationSubmit.browser.test.mjs

### Removed registration-only functionality

- app/Http/Controllers/Resident/RegistrationEmailVerificationController.php
- app/Http/Controllers/Resident/RegistrationOtpController.php
- app/Mail/RegistrationOtpMail.php
- app/Mail/ResidentRegistrationEmailVerificationMail.php
- app/Support/RegistrationEmailOtp.php
- app/Support/ResidentRegistrationEmailVerificationService.php
- config/registration.php
- resources/js/components/registration/EmailVerificationSection.tsx
- resources/js/hooks/useRegistrationEmailOtp.ts
- resources/js/pages/Resident/RegistrationEmailVerification.tsx
- resources/views/emails/registration-otp.blade.php
- resources/views/emails/resident-registration-email-verification.blade.php

The accompanying birthdate dropdown changes in Admin/Residents.tsx,
BirthdateInput.tsx and the shared Register.tsx input rendering are included.
The registration browser test verifies that all three date parts survive reload.
