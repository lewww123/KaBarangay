# Certificate issuance, purok tags, preview, and print

## Issued state

Manual certificate generation is handled by `CertificateController`, reached
through the Clerk portal. Successful generation persists a `CertificateIssuance`
with `status = issued`, the issuance timestamp, and the resident/purok snapshot.
The status is explicitly saved in the issuance transaction and returned in the
`X-Certificate-Issuance-Status` response header.

The browser checks that header and the DOCX attachment, then refreshes Requests,
Issued / Completed, and All Records. It opens the issued tab on page one and
clears issuance filters so the new record is visible immediately. Unknown
network/print outcomes refresh the history and block accidental resubmission.

`issued` records successful document generation. Linked resident requests follow
their payment/claim/release workflow and keep their separately tracked state.

## Purok

The manual entry/review form has a Purok field. Linked residents use the
authoritative purok from their barangay-scoped resident record. The final value
is saved on the issuance and used in both preview and generation.

Supported template forms:

- Existing Word merge field: `${purok}`.
- Word merge aliases: `${PUROK}` and `${RESIDENT_PUROK}`.
- Plain text aliases: `{PUROK}` and `{RESIDENT_PUROK}`.

Purok aliases are supported in the document body, headers, and footers, including
tags split across Word formatting runs. The aliases normalize to the existing
`purok` template contract. Other missing/unsupported merge fields still fail
template validation. The plain tags also expand in generated text fields such
as purpose and business address. XML escaping preserves names containing `&`.
Already-prefixed purok names do not receive another `PUROK` prefix in Residency
or Marriage Cohabitation certificates.

## Full-design preview and direct printing

1. Open **Issue New Certificate**, enter the certificate details and purok.
2. Choose **Preview Certificate Design**.
3. Review the generated document, or use **Back to Edit** to update its data.
4. Choose **Print Certificate** to issue once and open the browser print dialog.

`POST /clerk/certificates/preview` uses the same template renderer and validation
as issuance, with no issuance/audit record or request-state change. Preview
requests are debounced and aborted when superseded. The final issuance requires
its normal detail confirmation even though preview can be reviewed first.

The browser uses dynamically loaded `docx-preview` to render the actual DOCX,
including its embedded images, seals, watermarks, fonts, headers, and footers.
Printing uses a separate same-origin iframe containing only the certificate.
Page dimensions come from the Word layout, with `@page` margin zero and
`-webkit-print-color-adjust: exact` / `print-color-adjust: exact`. Embedded fonts,
HTML images, and SVG/VML seal/watermark images finish loading before printing.

## Verification

- Applied `2026_10_29_000005_add_status_to_certificate_issuances.php`.
- Ran `php artisan config:clear` and `php artisan route:clear` successfully.
- Full Laravel suite: **1,257 passed, 1 skipped; 11,735 assertions; no failures or errors**.
- TypeScript, production build, and focused PHP formatting checks passed.
- Headless Chrome print-layout check passed: one complete certificate page,
  dynamic purok, header/footer, two seal/watermark drawing images, original
  typography/color, exact print-color adjustment, zero page margins, and no
  portal navigation in the print frame.
