# Emergency Directory

## Data ownership

- `shared_emergency_contacts` is the only source for emergency service contacts
  displayed to residents in the Emergency Help panel.
- `emergency_contacts` remains barangay-owned data for `barangay_hotline`
  records only, including Tanod and barangay desk contacts.
- Barangay administrators may create, edit, and delete only their own
  `barangay_hotline` records.
- Shared emergency contacts are read-only for barangay administrators.
- Existing legacy `emergency_hotline` rows are left untouched but are not
  displayed or editable through ordinary barangay administration.
- New barangays receive no default hotline records.

## Safe initialization

The shared directory intentionally starts empty. There is no automatic seeding
and no fallback contact list. Do not derive shared records from the retired
`EmergencyContactSeeder`, from unverified spreadsheets, or from legacy
`emergency_hotline` rows.

Only a reviewed directory file should be imported. A record should be imported
only after its service, coverage area, phone number, capabilities, and
verification source have been confirmed by the responsible reviewer.

## Import format

The input is a JSON array. Every row requires:

| Field | Meaning |
| --- | --- |
| `source_key` | Stable unique identifier used to prevent duplicate imports |
| `service_type` | One of `City Emergency`, `Ambulance`, `Fire`, `Police`, `Hospital`, `Disaster` |
| `name` | Service or agency name |
| `label` | Optional short label |
| `phone_number` | Dialable number; normalized to 3-15 digits with an optional leading `+` |
| `coverage_area` | Area served by the contact |
| `can_call` | Whether the Call action is allowed |
| `can_sms` | Whether the SMS action is allowed |
| `display_order` | Non-negative display ordering value |
| `is_active` | Whether the record is available to residents |
| `verified_at` | ISO-8601 verification timestamp |
| `verification_source` | Human-readable description of the review source |

The importer rejects invalid rows, invalid phone numbers, unsupported service
types, and duplicate `source_key` values in the same file.

## Import procedure

Run a dry run first:

```powershell
php artisan emergency-directory:import path\to\reviewed-directory.json
```

After reviewing the validation result, persist the records with a responsible
staff verifier:

```powershell
php artisan emergency-directory:import path\to\reviewed-directory.json --apply --verified-by=<staff-id-or-email>
```

Production writes are refused unless `--force` is also supplied. Use that flag
only as part of an approved deployment and after taking a database backup.

The importer:

- inserts only records whose `source_key` is not already present;
- never overwrites edited records;
- never resurrects soft-deleted records;
- writes an audit event for each inserted record.

## Deployment checklist

1. Confirm the directory has been reviewed and the verification source is
   recorded.
2. Back up the target database.
3. Run the dry run and review any validation errors.
4. Apply the reviewed file with a valid `--verified-by` staff account.
5. Confirm the shared Emergency Services panel is read-only for a Barangay
   Admin and that the Resident dashboard shows the expected contacts.
6. Confirm legacy `emergency_hotline` rows are not displayed or editable.
7. Do not run the retired `EmergencyContactSeeder`.

## Tests

Focused coverage lives in
`tests/Feature/EmergencyContactManagementTest.php` and includes shared
visibility, write denial, barangay isolation, normalized-phone deduplication,
empty states, importer idempotency, soft-delete preservation, URI generation,
and retired-seeder behavior.
