# Lupon Tagapamayapa integration review

## Delivered workflows

- Existing `lupon_cases` remains the single docket table. No parallel `cases` table or duplicate resident directory was introduced.
- Online complaints accept private supporting evidence, retain their separate intake review states, and link to the docket after staff acceptance. Tracking reloads every 15 seconds while the page is active; it uses polling, not WebSockets.
- Staff intake shares complainant/respondent controls: keyboard QR scanners, camera QR scanning, resident reference lookup, debounced indexed name search, authoritative profile autofill, and manual walk-in entry. It does not preload the resident masterlist.
- Accepted complaints and walk-ins use the same docket completion path. A scheduled initial hearing prepares a Notice of Hearing and a separate Summons. Legacy API submissions without a hearing create pending document tasks, never invented meeting dates.
- The case screen combines guarded actions, meeting records, deadline history, evidence, fee/waiver verification, generated documents, actual service recording, and immutable written amicable settlements.

## State and deadlines

Canonical docket states: `filed`, `mediation_active`, `pangkat_formed`, `conciliation_active`, `settled`, `repudiated`, `dismissed`, `certified_to_file_action`.

Phase derives from state. Staff cannot independently change phase or reopen closed cases. Changes lock the case row, validate the previous state, and record an audit event. Stale edits are rejected when the submitted expected state changed.

Deadlines use Philippine calendar dates, independent of the application's UTC timestamp setting:

- Next working day summons target starts from receipt; promoted online complaints retain their original submission date. Weekends and configured holidays are excluded.
- Mediation: 15 days from the actual first meeting; scheduling does not start the clock.
- Pangkat: three recorded members, with different selected chair and secretary; convening target is three days from constitution.
- Conciliation: initial 15 days from the first Pangkat meeting, plus one recorded, reasoned extension of at most 15 days.
- Settlement: ten-day repudiation window. This workflow records sworn repudiation based on fraud, violence or intimidation; it does not adjudicate whether allegations are true.
- A deadline never automatically produces a CFA. Staff must verify the grounds and prerequisites. Failed conciliation requires recorded Pangkat officials and a held conciliation hearing. CFA generation and its state change commit together.

The legal timing basis is [RA 7160, sections 410–418](https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/2/53542). Staff remain responsible for jurisdiction, the factual and legal grounds, service requirements and required original signatures. The application supports written amicable settlements; arbitration awards have different prerequisites and are not mislabeled as amicable settlements.

## Documents and signatories

`DynamicDocumentEngine` is shared with certificate generation. `StagedDocument` completes DOCX/ZIP revisions in the system temporary directory, writes a unique destination once, cleans temporary/partial files on failure and never overwrites a previously issued file. Database rollback removes newly created private files.

Document kinds: `notice_of_hearing`, `summons`, `amicable_settlement`, `cfa`. Files, signer snapshots and generation/service history are persisted. Repeated preparation is idempotent; changed schedules or acting signatories create a new version and supersede the affected notice.

The selected acting captain must be a published Kagawad in the same barangay. The engine renders their acting title and excludes their identity from the regular board. A Pangkat CFA uses its recorded secretary and chair; a Lupon CFA after repudiation uses the published secretary and captain. The engine does not automatically append all Kagawads as statutory Lupon signatories.

Generated notices are distinct from served notices. Fee/waiver verification does not invent service, issue resident notifications or mark the respondent summoned. Staff record the actual receipt date, service method and acknowledgment. Verified linked respondents then receive an outbox notification after commit. Unserved notices are not exposed in resident document downloads.

## Privacy and deployment

- Every staff endpoint requires an active Lupon assignment in the case barangay. Admin/clerk access is not implicitly widened. Resident access requires the complainant identity or a verified respondent association.
- Evidence is on Laravel's private local disk, with protected attachment downloads and no public storage URLs. Only its owner, complaint author, authorized staff, or verified parties explicitly granted shared visibility can read it. Respondent narrative redaction is preserved.
- Up to five evidence files per submission, 5 MB each: PDF, JPG/JPEG, PNG, WebP or DOCX. Configure PHP `upload_max_filesize` and `post_max_size` to accommodate these limits. This is file-type validation; no malware scanning service is added.
- PostgreSQL lifecycle CHECK constraint and leading indexes protect the new relationships. Row-level security is enabled on all eight Lupon tables. There are no client-side policies; access runs through Laravel's owning/server database role. Keep database credentials server-only. Rolling back the feature intentionally retains protection on existing Lupon tables.
- Set `LUPON_HOLIDAYS` to the applicable national/local non-working dates (`YYYY-MM-DD`, comma separated). The default calendar only knows weekends.
- Optional approved DOCX paths: `LUPON_NOTICE_TEMPLATE`, `LUPON_SUMMONS_TEMPLATE`, `LUPON_SETTLEMENT_TEMPLATE`, `LUPON_CFA_TEMPLATE`. Blank paths use clearly marked unsigned system layouts. Publish the relevant officials before preparing documents. Configure approved local forms before official release; system layouts do not claim to be exact KP numbered forms.
- Approved template fields: `province`, `city`, `barangay`, `case_number`, `case_title`, `case_type`, `complainants`, `respondents`, `complainant_addresses`, `respondent_addresses`, `hearing_date`, `hearing_time`, `venue`, `issued_date`, `settlement_date`, `settlement_terms`, `mediation_date`, `conciliation_date`, `repudiation_date`, `cfa_basis`, `punong_barangay`, `punong_barangay_title`, `kagawad_names`, `pangkat_chair_name`, `pangkat_secretary_name`, `certifying_secretary`, `secretary_title`, `attesting_chair`, `attesting_title`. Use `${field}` merge syntax. Multiline terms preserve line breaks. Unknown fields reject generation.

## Migration and legacy data

Migrations `2026_11_02_000001` and `2026_11_02_000002` integrate the module and index its audit/document links. Legacy status labels are translated; unresolved records are flagged for continued conciliation review, never silently converted into CFA. Actual meeting dates are left unasserted. Staff can complete missing historical milestones with source notes; verified dates cannot be silently replaced. Existing written settlement dates are retained.

Supabase preflight found no existing docket rows. Both migrations were applied and protected-table/server-access checks were performed. No real resident case was created as a deployment test.

## Verification

Final complete suite: **1,251 passed, 17 skipped, zero failures** (1,268 tests; 11,940 assertions). Focused integration/regression run: **165 passed**. TypeScript checking, targeted frontend lint, PHP formatting and the production build passed. The final build preserved existing assets to avoid a transient Windows directory-cleanup lock. Integration tests cover online evidence transfer, QR/tenant access, original receipt dates, actual-meeting clocks, guarded transitions, extensions, settlement/repudiation, generated DOCX validity, filtered acting signatories, Pangkat CFA officials, notice versioning, service privacy, rollback and temporary-file cleanup. PHP formatting, targeted TypeScript lint, type checking and the production build are checked. Desktop intake/case and tablet intake/resident screens were rendered and visually reviewed. A physical camera requires a secure browser context and device permission; hardware capture is not exercised by the automated suite.
