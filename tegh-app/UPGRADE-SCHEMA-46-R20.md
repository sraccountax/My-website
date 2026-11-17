# Protected Schema 46 staging upgrade

## Before changing staging

Verify the R20 archive against the supplied SHA-256 file. Take and verify a coordinated backup of application files, the complete database, private config and all private document storage. Record the existing build, revision and database marker. Confirm the target is the intended staging site, not production. No deployment or host upgrade has been performed by the author of this package.

R20 is cumulative from R19 / Schema 45. Keep the existing private-config location and private-storage root unchanged. Do not extract the source/tests/evidence bundle into any public directory. PHP needs the existing application extensions plus Fileinfo and native uploads; the test environment did not have a PDO database driver. Production database support remains the application's MySQL/MariaDB configuration.

## Upload and upgrade

1. Upload the flat-root R20 application files to staging, preserving private files. Do not replace the retained database with `api/schema.sql`; it is a fresh-install schema, not an upgrade script.
2. Reload without stale assets and verify `tegh-build.json`: revision R20, build 5990, schema target 46, cache revision `5990-r20`. A health target is not proof the database migration completed.
3. Sign in as the existing Platform Owner. Run the protected database preflight/diagnostic through the application's existing startup upgrade interface. The endpoints remain `startup/migration-preflight`, `startup/migration-diagnostic` and `startup/migrate`; they now target Schema 46.
4. Confirm the verified backup in the owner upgrade dialog. The POST remains authenticated, owner-only and CSRF-protected. It uses the application's maintenance gate and advisory upgrade lock.
5. The normal 45-to-46 change adds only `invoice_document_operations` and `invoice_attachments`, validates their structures/indexes/foreign keys, records migration receipts and then adopts marker 46. No R20 journal is created. Existing incomplete supported Schema 43–45 prerequisites must be completed and checked first; this path has not been exercised on real MariaDB in this workspace.
6. Re-run preflight and verify readiness, database marker 46 and startup `invoiceDocumentsReady=true`. Structural mismatch or checksum conflict must be diagnosed rather than bypassed. Missing new tables are resumable; incompatible partial columns/indexes/keys intentionally require a diagnostic.
7. Verify PHP upload limits support the advertised files: for a 10 MiB attachment, use `upload_max_filesize` of at least 10M and `post_max_size` greater than the complete multipart request (12M or higher is a suitable example). Also check host/proxy request limits. Confirm that private storage is writable by PHP and is not web-accessible.
8. Confirm the existing outbound mail configuration and sender authorization. Use one dedicated controlled QA mailbox for the live send test; do not send a real customer invoice during QA. An SMTP acceptance is not proof of inbox delivery.

## Required staging checks

Use a fictional QA company and separate authorized owner/bookkeeper/viewer accounts. Test monthly P&L against posted GL for selected/prior ranges and compare screen, PDF, Excel and print. Test same dates last year, previous equal-length period, custom comparison, negatives, zero prior balances and prior-only inactive accounts. Confirm one-company scope and saved filters.

Test draft line edits and issued terms-only edits; verify totals and original GL journal identities before/after. Attempt stale revisions, wrong-company IDs, viewer writes, closed periods and duplicate operation keys. Test issue/void while an email receipt is pending, including the Transaction Void workflow. Test both independent requests and concurrent requests using actual MariaDB locks.

Upload allowed files, reject mismatched/executable/oversized files, download only through authenticated company-scoped endpoints and confirm removal retains private evidence. Test configured 10 MiB and 20-active-file boundaries, native error responses and CSRF/session expiry. Validation is not malware scanning.

Send only after preview and explicit confirmation. Confirm receipt history, sender/recipient, exact invoice content and chosen attachments. Test rejected and ambiguous outcomes without blind automatic resending. Verify the controlled inbox independently. The invoice is in the email body; no automatic invoice-PDF attachment is added. A PDF can be uploaded and explicitly selected as a supporting file.

Rehearse full backup/restore with attachment bytes and operation history. Restored sending records become manual-review records and must not resume transport automatically. Verify authenticated desktop/mobile navigation, exports and unrelated module regressions before production acceptance.

## Rollback

Before any database upgrade, restore the R19 file/config backup if needed.

**After Schema 46 has been adopted, do not roll back by copying only R19 files.** Older code may reject a newer schema and lacks the new document-state handling. Restore the coordinated R19 application/database/private-storage backup together in maintenance mode, or retain R20 while repairing a diagnosed issue. Do not drop populated tables or move the marker backwards as a shortcut.

Invoice emails already accepted by an external mail service cannot be undone by a database rollback. Preserve their submission evidence and avoid a second send. Preserve R20 attachment evidence created after the backup before restoring an older snapshot.
