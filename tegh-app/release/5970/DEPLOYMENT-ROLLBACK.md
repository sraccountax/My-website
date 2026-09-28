# Staging deployment and rollback — Tegh 5.9.7 / 5970 / Schema 44

**HOLD / NOT SEALED. This checklist authorizes no production deployment.** Use an isolated IONOS test host and fictional or properly sanitized cloned data. Verify all host details rather than assuming the local container reflects IONOS.

## Inventory and backup before any upload

Record the actual document root, PHP web-handler and CLI versions, MySQL/MariaDB engine/version, InnoDB and utf8mb4 behavior, SQL mode, timezone, max execution/memory/upload/post limits, advisory-lock support, HTTPS host name and private paths. Confirm PDO MySQL, mbstring, DOM/XML, ZipArchive, OpenSSL, fileinfo and required application extensions. Record SMTP/TLS settings without copying credentials into evidence. Check the enabled extension names in the web handler as well as CLI.

Find the real private configuration from `SR_ACCOUNTAX_CONFIG` or the established sibling `sr-accountax-private/config.php` path. Preserve the existing application secret, database credentials, storage/documents, incident log, backups, mail queues and session configuration outside the public root. **Do not replace a completed private config with config.example.php. Do not rotate the application secret during this migration.** Rotation invalidates signed workbook state, encrypted mail tokens and other existing protected data.

Take a consistent full database backup and an exact file/private-storage backup at the same cut, with hashes and a tested restore into a different database. Never put the backup, credentials, customer files or sessions inside this deployment ZIP or a public folder. Save the original supplied 5960 deployment separately. Enter maintenance mode before replacing files or migrating. Drain writes and check that no other migration is active.

## Isolated staging procedure

1. Create a fresh staging document root and a separate database. Extract this ZIP directly into that root: `app.html`, `api/` and `assets/` must be immediately inside it, not inside a second enclosing directory. Verify `FILE-MANIFEST.sha256` and the external archive SHA-256 before use. Keep the protected `release/` documentation directory non-public.
2. Point the staging private configuration to the staging database and storage only. Use HTTPS and the exact staging `app.base_url`. Disable real outbound mail or direct it exclusively to an authorized test sink. Keep `app.public_signup_enabled=false`; do not enable public registration merely to test an invitation. The Connected Intelligence release gate must remain false even with a fake configured key.
3. Restore a sanitized Schema 43 clone into the staging database. **Do not run schema.sql as a fresh install over a retained database.** Use the existing Platform Owner protected migration UI. Inspect `startup/migration-preflight` and download its diagnostic. The new migration requires prior supported schema readiness, an explicit backup confirmation, CSRF and Platform Owner authorization.
4. Run the protected `startup/migrate` workflow for Schema 44. Do not execute the DDL file alone as a substitute: it cannot perform invitation/native/source data backfills or append the migration ledger. Capture preflight, each step/reference, structural table/index checks and the final schema marker. Re-run the same protected workflow; it must report already-completed steps with no duplicate history/revisions/receipts. Test an interrupted migration and concurrent upgrade in separate clones.
5. Check fresh `health`, authenticated schema readiness, active source references and 5.9.7 / 5970 / 44 metadata. Verify sign-in, invitation acceptance while public signup is off, and last-company/no-company behavior. Clear only application caches using the established version mechanism; do not delete retained accounting storage or security configuration.
6. Run every uncompleted gate in the requirement matrix, especially real PHP/MySQL posting/category/invitation/deletion tests, all report reconciliation fixtures, all import entry points, zoom/accessibility and real controlled-mail delivery. Compare before/after journal counts and balances. Export/preview/category-only cases must not create accounting entries. Confirm all provider call/reservation counters stay zero with a seeded stale opt-in.
7. Obtain independent accounting and security sign-off against the actual results. Keep a signed release record and rollback rehearsal evidence. No production upload should occur until this package has been re-reviewed and a separately authorized sealed release has been issued.

## Schema 44 contents and idempotency design

Thirteen new InnoDB tables are declared in `api/schema44_v5970.sql` and the protected migration's table registry. They hold the audited release setting/history; invitation parents/assignments/tokens/mail/attempts/terms acceptance; durable bank operation headers/rows; category commit receipts; retirement markers; full source descriptions. Structural readiness checks tables, columns and indexes rather than trusting only the numeric schema marker.

The forward process adds tables using CREATE TABLE IF NOT EXISTS, then performs transactional data changes and adopts the marker. Existing journal/voucher IDs are not regenerated. Native entitlements preserve explicit Platform Owner decisions/suspensions and user-level narrowing. Existing pending signup intents gain retirement records rather than deleting their history. Stale connected opt-ins are set false with policy hashes/audit history updated. Legacy invitation records/tokens are copied idempotently. Matching retained statement preview evidence backfills full descriptions when its fingerprint and prefix checks agree.

The implementation uses advisory-lock/maintenance protections and append-only migration events. SQL CREATE TABLE operations are not transactionally undone by an application rollback. No automatic destructive down migration is supplied. **Forward, rerun, interruption, concurrency and restoration behavior is not runtime-verified in this delivery.**

## Rollback

Before Schema 44 runs, a staging code-only rollback may restore the exact preceding app root and its cache references, provided no incompatible writes occurred. Preserve private storage/configuration. Verify hashes and fresh authorization afterward.

After Schema 44 begins, do not simply upload Schema 43-era PHP over the changed database, edit the schema marker backwards, DROP new tables, TRUNCATE receipts, or erase migration history. Keep maintenance mode active. Restore the paired database snapshot and matching old app/storage snapshot into an isolated target first; verify all balances, memberships, invitations, receipts and file links. Switch staging back only after that restore passes. A rollback that preserves post-upgrade business activity requires a separately reviewed reconciliation/recovery plan, not a generic SQL down script.

**Irreversibility warning:** sent email cannot be unsent. Consumed invitation links, policy changes, external side effects and accounting activity after the backup cut cannot safely be erased by restoring yesterday's database. Revoke/reconcile such effects explicitly under authorized procedures. Never restore over newer production books merely to downgrade this candidate.

## Do not seal until

Actual PHP/MySQL integrations and migration recovery pass; every requested accounting/security/permission/concurrency fixture passes; native-provider isolation is proven across endpoints; all current import/report routes and responsive/zoom/accessibility gates pass; real test-sink email delivery and DNS/alignment are verified; the final package passes extraction/hash checks; independent humans approve accounting and security. Retain HOLD while any item is blocked, unrun, failed or unreviewed.
