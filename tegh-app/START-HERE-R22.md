# Tegh R22 — staging deployment

**Cumulative application candidate. Not deployed, sealed or approved for production.**

Version 5.9.9 / Build 5990 / Schema 46 / R22. This package includes the latest Schema 46 R20 invoice/report services and the R21 manual opening confirmation repair. The other earlier R20 Schema 45 package is not this baseline. No new database migration is introduced from Schema 46.

## Before deployment

1. Use the staging site and a dedicated fictional QA company. Take a coordinated application, database and private-document backup; establish a restore point.
2. Confirm current schema and private config/storage paths through the existing protected Platform Owner preflight. A retained Schema 45 database still needs the existing protected R20 Schema 46 upgrade described in `UPGRADE-SCHEMA-46-R20.md`. Do not run fresh-install SQL over an existing database.
3. Extract this full application ZIP to the existing application root containing `app.html`. Preserve private configuration, uploaded documents, incident logs and server-specific files. Do not delete the assets directory or replace the site with the separate evidence ZIP. The ZIP does not contain credentials or production configuration.
4. Verify `tegh-build.json` says R22 / Schema 46 / cache `5990-r22`. Reload the app with a fresh tab / hard refresh. Check the two active stylesheets and the required ActivityShell module load successfully.
5. Verify actual company/mode and viewport, then test the representative routes, overlays, final records and financial actions with the staging verification checklist. Local fixture screenshots are not substitutes for these checks.

## Required staging regression

Manual opening balances: wait for all saves, review one confirmation, cancel safely, confirm once, check the returned voucher and Trial Balance. New Pay Run remains a draft and must not post GL. Test invoice Send/Edit/Attachments under allowed/denied roles, including stale-record and interrupted responses. Verify monthly/prior-period/variance P&L and exports against identical server results. Test import previews, logical-record selection, cancellation, duplicate clicks and errors; matching is not posting and the interbank counterpart remains match-only.

Check 1920×900 and 1536×760 CSS pixels plus the actual usable laptop viewport; expanded 248px and collapsed 72px sidebar, single-row top navigation, narrow screens and actual browser zoom. Do not accept clipped financial values or unreachable last rows/actions. Strict short-form fit and internal grid scrolling are separate outcomes. The declared matrix target is 99%, not an achieved real-world use statistic.

## Rollback

Keep the exact previous R20 Schema 46 + R21 application backup. If schema is unchanged and no other release changed server storage, restore that complete managed application set (including its app.html, gate, CSS and manifest), remove only R22-only managed files, restore its cache revision and reload clients. Never delete private config or uploaded files. If any schema/storage upgrade or writes have occurred, use a coordinated matching database/application/private-storage restore rather than copying an older schema application over newer data. Rehearse this on staging.

Historical release documents in this cumulative archive describe older scopes; they do not certify R22. Current test results and limitations are in the separately supplied R22 verification report/evidence.
