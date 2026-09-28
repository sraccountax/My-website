# Tegh R23 — unified registers and shared UI repair

**Cumulative staging application; not deployed or production sealed.**

Version 5.9.9 / Build 5990 / Schema 46 / Revision R23 / cache `5990-r23`.
This archive is built from the exact R22 cumulative application. It includes the
Schema 46 R20 invoice/report services and R21 manual opening confirmation repair.
The unfinished prior R23 screenshots were not a recoverable source package.

## Safe staging deployment

1. Take a coordinated application, database and private-document backup. Use a fictional QA company, not a real customer company, for verification.
2. Confirm the server is on Schema 46 through the protected owner preflight. No new database migration is introduced. A retained Schema 45 database still needs the protected R20 upgrade described in `UPGRADE-SCHEMA-46-R20.md`; never run fresh-install SQL on an existing database.
3. Extract this COMPLETE deployment ZIP into the existing application root containing `app.html`, preserving the `api` and `assets` paths. Preserve private configuration, uploaded documents, private logs and host-specific files. Do not delete the site/assets directory. Do not upload the separate source/test evidence bundle.
4. Verify `tegh-build.json` reads R23, Schema 46 and `5990-r23`. Open a fresh tab or hard refresh. Confirm `tegh-registers-r23.css` and the required `tegh-registers-r23.js` both load. Cache-busting query strings are part of the matched asset set.
5. Test customer/vendor invoice registers with actual permitted roles, dates, long names, CAD/USD, tax, terms and opening documents. Test the Actions-header menu, column selection/filtering, row menus and original New Invoice / New Customer / New Vendor routes. Exports must include the chosen full result, not just one display page.
6. Verify dashboard shortcuts, expanded/collapsed/top navigation, selected/unselected customer/vendor ledgers and their open-payment panels. Verify P&L months, prior values, variances, zero-prior N/A, source totals and exports against server results.
7. Retest manual opening balances (one confirmation), invoice Send/Edit/Attachments, draft payroll, bank review/import/reconciliation and period-locked/permission-denied operations. No UI change authorizes posting or silently retries a financial operation.
8. Test real browser zoom, laptop viewport, mobile touch/keyboard, final rows and native popover/dialog menus. Readability/reflow takes precedence over forcing all columns or controls onto one row.

## Data and viewing details

The top row keeps title/search/date/apply/clear and relevant creation controls.
The table Actions ⋮ menu contains Export, Choose columns, Filter columns and Reset
view. Header arrows open typed column filters; header labels sort. Row Actions ▾
remain separate. Filtering applies to the whole complete result already authorized
by the server. The footer paginates its display; exports use the full filtered or
selected record set and either chosen or all supported columns.

Monetary values retain their currency and precision. Chosen fields that cannot fit
are available in More details; narrow tables become labelled stacked records.
A filtered ledger/financial statement is a record view, not a newly balanced
statement. Closing/running/net values are not recalculated. Source totals remain
explicit and are not presented as totals of a filtered subset.

## Rollback

When database/schema and private storage are unchanged, restore the complete R22
managed application backup, remove only R23-only managed assets/documents, restore
its cache/manifest, and reopen clients. Do not delete private files or restore only
one stylesheet. Where schema/storage or live financial writes changed, coordinate
a matching application/database/private-storage restore. Rehearse rollback on staging.

Historical release notes remain in this cumulative archive; they describe earlier
versions and do not certify R23. Current limitations are in `tegh-build.json` and
the separately supplied R23 audit/evidence report.
