# Sicsican Business Clearance

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
Business Clearance. Other barangays have no approved layouts yet. Legacy
catalog copies are hidden without deleting requests or issuance records.
Historical issued PDFs retain their existing barangay ownership restrictions.

Validation: `npm run types:check`, PHP syntax checks, and
`php artisan test --filter=CertificateTenantAccessTest`. The tenant test covers
catalog filtering, layout options, artwork access, and cross-barangay generation.
