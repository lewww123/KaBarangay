# Certificate Template Assets

This document describes how the certificate DOCX templates that back
`POST /clerk/generate-certificate` are stored, versioned, and provisioned in each
environment.

## Runtime location

The templates the application renders are read from:

```
storage/app/templates/
```

Each certificate type maps to a template stem in
`app/Support/CertificateTemplates.php` (the `FILES` map). The current mapping is:

| Certificate type | Template stem | File |
| --- | --- | --- |
| Indigency | `indigency` | `storage/app/templates/indigency.docx` |
| Residency | `residency` | `storage/app/templates/residency.docx` |
| Delayed PSA Registration | `delayedpsa_dynamic` | `storage/app/templates/delayedpsa_dynamic.docx` |
| Marriage Cohabitation | `marriage` | `storage/app/templates/marriage.docx` |
| Scholarship Certificate | `scholarship` | `storage/app/templates/scholarship.docx` |
| Tricycle | `tricycle` | `storage/app/templates/tricycle.docx` |

`isAvailable()` only reports a type as available when the corresponding file
exists on disk, so a missing template disables that certificate type at runtime.

## These assets are deliberately Git-ignored

`storage/app/.gitignore` ignores everything under `storage/app/` (`*`). The DOCX
templates are therefore **not tracked by Git** and are not part of any commit.
This is intentional: the binaries are large and are treated as deployable
content rather than source.

Because the templates are ignored, changing a template is an out-of-band asset
change. It will not appear in a pull request, a diff, or a release archive built
from the repository alone.

## Deployment requirements

A deployment **must** explicitly provision or synchronize the approved template
files into `storage/app/templates/` on the target environment. Do not rely on
`git clone` / `git pull` to deliver them.

Additional requirements:

- Preserve the existing **Tricycle** template and every other required template.
  Overwriting `tricycle.docx` with an unapproved file will change the Tricycle
  certificate output.
- Keep templates byte-identical to the approved source of truth for each
  environment; do not re-save them through a word processor during deployment.
- The **Indigency** template must contain the following merge fields, each
  intact within a single Word run (not fragmented across runs):
  - `${resident_name}`
  - `${purok}`
  - `${purpose}`
  - `${current_date}`
  - `${signatory_name}`

  The controller uppercases `resident_name`, `purok`, `purpose`, and
  `signatory_name` before writing them, so the rendered document shows
  uppercase values; `current_date` is generated server-side.
- The **Scholarship Certificate** template must contain the following merge
  fields, matching the server-side keys exactly:
  - `${resident_name}`
  - `${purok}`
  - `${school_institution}` (normalized from the original `school/institution`
    placeholder; the slash form is no longer supported)
  - `${father’s_name}`, `${father’s_occupation}`, `${father’s_monthly_income}`
  - `${mother’s_name}`, `${mother’s_occupation}`, `${mother’s_monthly_income}`
  - `${purpose}`
  - `${current_date}`
  - `${signatory_name}`

   The six family keys use a **curly apostrophe** (`’`, U+2019), not the ASCII
   `'` (U+0027). Re-saving the DOCX through a word processor must not change that
   character or the key spelling, or the merge will silently fail and leave the
   placeholder text in the output. These placeholders may be fragmented across
   Word runs; PHPWord resolves them as long as the concatenated run text equals
   the key exactly. The Scholarship family/school values are transient form data
   and are **not** persisted to the issuance history.
- The **Delayed PSA Registration** template must expose the eleven fields in
  `DelayedPsaCertificate::TEMPLATE_KEYS` (plus optional official fields):

  | Merge field | Notes |
  | --- | --- |
  | `${control_number}` | Intake field: `control_no`. |
  | `${resident’s_name}` | Curly apostrophe (U+2019). |
  | `${purok}` | Linked resident profile or manual intake fallback. |
  | `${date_of_birth}` | Long date, from profile or intake fallback. |
  | `${gender}` | Normalized MALE or FEMALE. |
  | `${father’s_name}` | Curly apostrophe (U+2019). |
  | `${mothers_maiden_name}` | Mother's maiden name. |
  | `${parents_marriage_details}` | Intake date and place, or explicit alternative text such as Not married or Unknown; printed uppercase. |
  | `${residency_period}` | Intake `residency_start_year`, rendered as `<year> up to present`; validated against birth year and current year. |
  | `${current_date}` | Long issue date. |
  | `${signatory_name}` | Optional published signatory selection. |

  The issuance modal and resident request intake collect marriage details and
  residency start year. These values are merged into both preview and issued
  documents. They are not inferred from birth date or marital status.
  The template retains its fixed purpose, nationality, and birthplace wording.
  The updated template is versioned with the application; the old local
  `delayedpsa.docx` is no longer the default template.

- The **Marriage Cohabitation** template must contain exactly the following
  seven merge fields, and no others:

  | Merge field | Notes |
  | --- | --- |
  | `${resident’s_name}` | Curly apostrophe (U+2019). Printed in uppercase; the resident is named twice on the certificate. |
  | `${resident_age}` | The age the resident has reached, computed server-side from the linked resident's birth date. |
  | `${partner’s_full_name}` | Curly apostrophe (U+2019). Printed in uppercase. |
  | `${partner’s_age}` | Curly apostrophe (U+2019). Whole number between 1 and 120. |
  | `${purok}` | The resident's purok; printed as `PUROK <name>`. |
  | `${years_living_together}` | Whole number between 0 and 120. |
  | `${current_date}` | Issue date, long form, e.g. `1st day of January 2026`. |

  The template deliberately has **no** `${purpose}`, `${control_no}`, `${fee}`,
  `${signatory_name}`, or `${signatory_title}` merge field: the document states
  its own purpose ("for Marriage Purposes") and carries its own Punong Barangay
  / Officer on Duty signature blocks, so the Clerk form does not collect a
  purpose or a signatory for this certificate type.

  The resident name, age, and purok come from the **linked resident profile
  only**; a client-supplied value is never trusted, so this certificate can
  only be issued against a registered resident of the acting Clerk's barangay.

  The partner full name, partner age, and years living together are
  **transient** form data. They are written into the document but are **not**
  persisted to the issuance history or to the audit log.
- The **Residency** template must contain exactly the following five merge
  fields, and no others:

  | Merge field | Notes |
  | --- | --- |
  | `${resident’s_name}` | Curly apostrophe (U+2019). Printed in uppercase. |
  | `${residents_name}` | The same trusted name, spelled without an apostrophe. The template names the resident twice. |
  | `${purok}` | The resident's purok; printed as `PUROK <name>`. |
  | `${years_of_residency}` | Whole number between 0 and 120; `0` means less than one completed year. |
  | `${current_date}` | Issue date, long form, e.g. `1st day of January 2026`. |

  The template deliberately has **no** `${purpose}`, `${control_no}`, `${fee}`,
  `${signatory_name}`, or `${signatory_title}` merge field: the document states
  its own purpose ("PROOF OF RESIDENCY."), carries its own Punong Barangay /
  Officer on Duty signature blocks, and prints no control number, so the Clerk
  form does not collect a purpose, a signatory, or a control number for this
  certificate type.

  The resident name and purok come from the **linked resident profile only**;
  a client-supplied value is never trusted, so this certificate can only be
  issued against a registered, active resident of the acting Clerk's barangay.
  It therefore cannot be issued against a manually typed name.

  The number of years of residency is **transient** form data. It is written
  into the document but is **not** persisted to the issuance history or to the
  audit log.

  The template prints the value literally followed by the word `years`, so an
  input of `5` reads `5 years` and an input of `0` reads `0 years`. A zero is
  the approved way to record less than one completed year; no special phrase
  such as "less than one year" is substituted into the document.

## The Delayed PSA Registration template preflight

The Delayed PSA template is checked before a document is rendered, because
PHPWord's `setValue()` is a plain string replacement: a missing merge field
would silently leave an unresolved `${...}` placeholder in the issued
certificate. `DelayedPsaCertificate::assertTemplateContract()` reads the
provisioned file with PHPWord and requires the variable list to match the
twelve approved keys exactly — a missing key and an extra key are both
rejected. When the check fails, the request is rejected with a validation
error on `certificate_type` and no document is produced.

Verify the provisioned file by hand with:

```bash
php -r "require 'vendor/autoload.php'; \
  \$p = new PhpOffice\PhpWord\TemplateProcessor('storage/app/templates/delayedpsa_dynamic.docx'); \
  \$v = \$p->getVariables(); sort(\$v); print_r(\$v);"
```

The output must list exactly:

```
birth_place
control_number
current_date
date_of_birth
father’s_name
mothers_maiden_name
nationality
parents_marriage_details
purok
residency_period
resident’s_name
sex
```

## The Marriage Cohabitation template preflight

The Marriage Cohabitation template is checked before a document is rendered, on
the same terms as the Delayed PSA template: a missing merge field would
silently leave an unresolved `${...}` placeholder in the issued certificate.
`MarriageCohabitationCertificate::assertTemplateContract()` reads the
provisioned file with PHPWord and requires the variable list to match the seven
approved keys exactly — a missing key and an extra key are both rejected. When
the check fails, the request is rejected with a validation error on
`certificate_type` and no document is produced.

Verify the provisioned file by hand with:

```bash
php -r "require 'vendor/autoload.php'; \
  \$p = new PhpOffice\PhpWord\TemplateProcessor('storage/app/templates/marriage.docx'); \
  \$v = \$p->getVariables(); sort(\$v); print_r(\$v);"
```

The output must list exactly:

```
current_date
partner’s_age
partner’s_full_name
purok
resident_age
resident’s_name
years_living_together
```

## The Residency template preflight

The Residency template is checked before a document is rendered, on the same
terms as the Delayed PSA and the Marriage Cohabitation templates: a missing
merge field would silently leave an unresolved `${...}` placeholder in the
issued certificate. `ResidencyCertificate::assertTemplateContract()` reads the
provisioned file with PHPWord and requires the variable list to match the five
approved keys exactly — a missing key and an extra key are both rejected. When
the check fails, the request is rejected with a validation error on
`certificate_type` and no document is produced.

Verify the provisioned file by hand with:

```bash
php -r "require 'vendor/autoload.php'; \
  \$p = new PhpOffice\PhpWord\TemplateProcessor('storage/app/templates/residency.docx'); \
  \$v = \$p->getVariables(); sort(\$v); print_r(\$v);"
```

The output must list exactly:

```
current_date
purok
resident’s_name
residents_name
years_of_residency
```

## Marriage Cohabitation asset provenance

| Asset | SHA-256 | Size |
| --- | --- | --- |
| `storage/app/templates/marriage.docx` (active) | `82913B9219C7B35BFE81067990FFE083BECEEC9D3B28E06E2B9E4C37639B43A3` | 2726900 bytes |

This template is provisioned as-is: it is the approved source of truth for the
Marriage Cohabitation certificate and must not be re-saved through a word
processor, which could rewrite the curly apostrophes in the merge keys.

## Residency asset provenance

| Asset | SHA-256 | Size |
| --- | --- | --- |
| `storage/app/templates/residency.docx` (active) | `E6C256353A76A3A66F4AF86DF8B8D6366865171E038C1F788405A1485292B33F` | 3036173 bytes |
| Approved source before the Phase 2 edit (rollback reference only) | `F27584230FF49501B71885D81E163133F52F23B80516A8E603683CAB43C39C2A` | 3054240 bytes |

The active asset is the approved source template with four minimal edits
applied to `word/document.xml` only — every other part of the package is
byte-identical to the source:

- the literal `Mr./Ms.` honorific was removed, so no honorific is invented for
  the resident;
- the hardcoded `10th day of APRIL 2026` was replaced with `${current_date}`;
- the resident name, the purok, and the number of years became merge fields;
- the word ` years` was appended after the `${years_of_residency}` placeholder,
  so the issued sentence reads, for example, `for 5 years`.

The pre-edit source is kept outside the runtime template directory as a
rollback reference. It is **not** an approved deployment asset: it still
carries the hardcoded date, the honorific, and the placeholder text, and it
must never be copied into `storage/app/templates/`.

Because the templates are Git-ignored, the edited asset is not part of any
commit. Every environment must be provisioned with the edited file, and the
active SHA-256 above must be verified after the copy:

```bash
sha256sum storage/app/templates/residency.docx
```

## Delayed PSA Registration asset provenance
| Asset | SHA-256 | Size |
| --- | --- | --- |
| `storage/app/templates/delayedpsa_dynamic.docx` (active) | `245F7AA3ED3053A8A9577C18F14500B0F8865789572D4A46722489EBE182DFA1` | 1887171 bytes |
| `storage/app/template-originals/delayedpsa.original.sha256-b5168b91.docx` (rollback) | `B5168B91C3332953F547086D25E3EBF3AC1D120C06E37C2764E47B51FC0BE6D4` | 2730933 bytes |

The rollback copy is the unmodified original template and is kept outside the
runtime template directory. It is **not** an approved deployment asset: it
carries the pre-change merge fields and must never be copied into
`storage/app/templates/`.

## Post-synchronization verification

After copying templates into place, verify:

1. **Presence** — the expected files exist:

   ```bash
   ls storage/app/templates
   ```

2. **Placeholder integrity** — the Indigency template exposes exactly the
   expected variables and none are fragmented. A quick check using the
   application's own PHPWord dependency:

   ```bash
   php -r "require 'vendor/autoload.php'; \
     \$p = new PhpOffice\PhpWord\TemplateProcessor('storage/app/templates/indigency.docx'); \
     var_dump(\$p->getVariables());"
   ```

   The output must list `resident_name`, `purok`, `purpose`, `current_date`, and
   `signatory_name`.

3. **Availability** — the focused regression suite confirms each available
   type has a real template file:

   ```bash
   php artisan test --filter CertificateGenerationTest
   ```

   Run it only in an environment where the test database isolation guard passes
   (SQLite in-memory); see `phpunit.xml` and `tests/TestCase.php`.

Do not add generated documents to the template directory.

## Generated documents are outputs, not templates

Files matching `storage/app/public/Certificate_*.docx` are **generated output**.
Each issuance writes a uniquely named file (`Certificate_<uuid>.docx`) and streams
it as a download; the controller deletes the file after sending. These files are
never source templates and must never be copied back into
`storage/app/templates/`.
