# Tegh R19 — reference-layout implementation candidate

Version 5.9.9 · Build 5990 · Schema 45 · Revision R19

**STAGING CANDIDATE — not deployed; authenticated acceptance pending.**

## Exact baseline

This cumulative flat-root package was built from the uploaded R18 PREMIUM-UI-OPENING-BALANCES archive with SHA-256:
`6538d6268dea71110cfac65a0402fc0301c0bc44880112c4e7d3c8d48975b222`.

No PHP, SQL, database migration, payroll calculation, manual-opening-balance backend, accounting-posting, configuration, or access-control backend file was changed. R19 changes presentation and navigation only. The R18 opening-balance frontend remains byte-identical. Existing authorization and authoritative-output checks remain in their normal code paths.

## Implemented

The dashboard now has a time-dependent greeting and company context, four financial cards with YTD net income rather than equity, compact Quick Actions, and a two-column Tasks Requiring Attention panel. Task values come from existing company-scoped summaries; no invented payroll-ready or overdue-count value was added. The cash forecast remains under More insights. Income/expense charts use signed values, a net-profit line, exact-data disclosure, and a width observer that responds when navigation changes available content width. Chart drilldown preserves its selected period.

The invoice register uses combined invoice/customer and invoice/due-date cells, untruncated currency amounts, badges, responsive record layouts, explicit selection scope, pagination and the existing complete-report export pipeline. Existing authorized record action nodes are retained. Filters collapse on phones without removing their inputs. The invoice detail view reads the existing validated invoice model and preserves source lines, totals, payments/credits and outstanding balance. Its Overview, Payment summary and Document details tabs support arrow/Home/End keys. Payment and journal actions delegate to existing workflows, with no new posting implementation.

The P&L presentation groups accounts, emphasizes subtotals and net results, and includes a signed selected-period chart and metrics. Amounts, period, currency and report identity come from the same authoritative model used by existing report exports.

Mobile navigation adds Home, Banking, Create, Reports and More. It moves the existing authorized Create menu instead of creating a second one. The white drawer reuses the original sidebar and temporarily moves the existing company selector. Profile/sign-out use existing handlers. Focus is trapped while open; Escape restores focus and prior background interactivity. Expanded, 64px collapsed and true top navigation share a fixed utility header.

## Tests actually executed

52 browser-component checks passed, 0 failed. Widths: 320, 390, 768, 1024, 1366 and 1440 CSS pixels. Checked the four changed working views, collapsed/top navigation, visible numeric bounds, completed chart data, zero/negative/missing chart values, exact report totals, 52-record pagination, selection focus, invoice detail navigation and tabs, mobile drawer focus/selector restoration, mobile filters, and a single Create control across resizing. No JavaScript page errors were captured.

**Test boundary:** Chromium ran the changed application components with an in-memory fictional API transport and a minimal core-shell fixture using the actual shell attributes/classes. The full authenticated React/core startup, real PHP/MariaDB services, generated PDF/Excel output, native zoom, screen readers and IONOS hosting were not exercised. The fixture layer bypasses startup only for offline component tests; it is not included in this deployment package. Screenshot data is fictional and not an accounting audit or staging evidence.

## Remaining differences / gates

The P&L currently uses the selected-period authoritative model. Month-by-month columns, prior-period columns and variance columns from the design image were not added. Invoice details do not introduce nonfunctional Send/Edit/Attachments buttons. The live permission-specific menus and all other modules still require a full authenticated visual review. No assertion of pixel-perfect parity or application-wide completion is made.

IONOS staging could not be reached from this environment: curl returned DNS resolution error 6. This does not establish that the website is down. No credentials were submitted, no IONOS upload occurred, no GitHub commit was made and no database upgrade ran. A reusable signed-in browser session was not available.

## Staging deployment and verification

Back up the staging application files and database. Verify the existing target/version and retain private configuration, credentials, document storage and uploads. Upload this package's flat-root application files to staging only; do not delete retained/private directories and do not run a fresh installation over an existing database. There is no R19 schema migration. Do not deploy it to production as a sealed release.

After upload, verify `/tegh-build.json` reports R19, cache revision `5990-r19`, build 5990 and schema 45. Reload the browser, confirm the new reference assets load and inspect a dedicated fictional QA company with actual posted monthly activity and sample invoices. Run the supplied staging verifier from the separate evidence ZIP using a temporary QA account or an authenticated browser state. Do not place passwords, session state or the test fixtures in the public web root or repository.

Compare the actual hosted screens to the reference in expanded/collapsed/top modes and at mobile widths, including below-the-fold tasks, open menus, failed/empty states and exact report exports. Re-run the real R18 PHP/MariaDB/API/browser regression workflow against this exact R19 ZIP; previous R18 results do not certify R19. Restore the backed-up R18 files if rollback is required; no R19 database change needs reversal.
