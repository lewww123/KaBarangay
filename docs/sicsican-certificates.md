# Sicsican certificates

## November 2026 replacement

The four supplied Word references replace the old image-overlay previews in
`SicsicanCertificatePreview.tsx`: RES (Barangay Certification), loan/emp
(Sicsican Clearance), fa/med/hosbill (Sicsican Indigency), and BUSINESS DTI
(Business Clearance). Preview, print and saved PDF share the same content.
Original background/seal assets are stored privately in the residency,
clearance, indigency and business subdirectories. The authenticated reference
artwork endpoint validates the variant/asset and checks barangay access.

The approved `approved-design.jpg` in each directory now supplies the full
image design, including the three logos, colored frame/title, dry-seal area
and footer. Existing certificate wording and published-captain fields are
retained above masked sample text. The sample reference number, date and
captain are replaced by the current issuance values.

Body wording and authority signature blocks follow the supplied DOCX references.
Names, puroks, purposes, residency years, business details, sex and the published
captain are populated at runtime. A sex selector supplies the document's gender
and pronouns. Signatories follow San Jose's shared official resolution: the
published captain is the default, an explicitly selected Kagawad becomes OIC,
and OIC output hides the separate authority block. The authority name comes only
from the selected signatory. Names in the DOCX are not fixed in the output.
Fonts and sizes remain unchanged.
The complete footer text and tiny seal come from the DOCX. Its printed verse
reference is retained verbatim, including 1:77 on residency and personal clearance.
The control-number block is shifted right and replaces the entire sample block.
Residency retains its fixed proof-of-residency purpose. Historical PDFs are unchanged.

Run migration `2026_11_06_000001_add_sicsican_clearance.php` to provision the
fourth catalog entry. The new entry starts at fee 0; existing settings and
history are preserved. Fresh catalogs are also supported by the seeder.

The sections below describe the superseded artwork endpoints retained for
compatibility; the new previews use the reference-artwork endpoint instead.

## Certificate of Indigency

The purple Sicsican artwork is stored privately at
`resources/certificates/sicsican/indigency.jpg`. Its catalog code is
`sicsican-indigency`, mapped to the separate PDF type `Sicsican Indigency` so
San Jose's `Indigency` Word/PDF layout is unaffected. The authenticated artwork
endpoint checks the acting clerk's barangay. Name, purok, purpose, issue date,
published captain, and reference are populated in the print/PDF preview.

Deploy migration `2026_11_05_000002_add_sicsican_indigency.php` to add the catalog
entry. It is idempotent and preserves any existing fee/settings. The seeder
supports fresh Sicsican catalogs. Initial fee is 0 pending the actual rate.

## Barangay Certification

The blue Barangay Certification artwork is also stored privately in
`resources/certificates/sicsican/barangay-certification.jpg`. Its separate
Sicsican-only catalog code is `barangay-certification`. Migration
`2026_11_05_000001_add_sicsican_barangay_certification.php` adds the entry without
overwriting existing configuration. Fresh catalogs are supported by the seeder.
The form captures resident name, purok, years of residency (0–120), and purpose.
The current issue date, published captain name, and per-type reference number
are populated for preview and the saved PDF. The initial fee is 0 pending the
barangay's actual rate. It requires no DOCX and does not use San Jose's layout.

The initial Sicsican clearance uses the supplied JPEG as its page artwork; no
DOCX is required. Preview, PDF download, and printing use the same filled page.
Owner, business address, establishment name/type, issue date, reference, and
published Punong Barangay name are filled at runtime. The original signature
name, reference, and date are covered before output. No signature is generated.

The artwork is versioned under `resources/certificates/sicsican/` and served
through an authenticated, barangay-checked endpoint with private/no-store caching.
Do not publish the source image as a public static asset.

Deploy the application and run the migrations to add the Sicsican catalog row.
The certificate seeder also provisions it for a newly added Sicsican barangay.
The sample provides no fee: the initial catalog fee is 0 and must be configured
to the barangay's actual rate before operational use.

Approved San Jose layouts are available only to San Jose; Sicsican sees only
Business Clearance. Other barangays retain their existing catalog and layout behavior. Legacy
catalog copies are hidden without deleting requests or issuance records.
Historical issued PDFs retain their existing barangay ownership restrictions.

Validation: `npm run types:check`, PHP syntax checks, and
`php artisan test --filter=CertificateTenantAccessTest`. The tenant test covers
catalog filtering, layout options, artwork access, and cross-barangay generation.
