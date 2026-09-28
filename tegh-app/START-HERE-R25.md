# Tegh R25 — preferences, scrolling and package-wide audit staging candidate

**Tegh 5.9.9 / Build 5990 / Schema 46 / R25. Staging candidate only. Not production sealed.**

R25 is cumulative from the exact R24 navigation-repair package. It retains the R20 invoice/report services, R21 manual opening-balance confirmation repair, R22 compact workspace, R23 unified registers and R24 top-navigation repair. **No new database migration is required from Schema 46.**

## What R25 changes

- Tegh Preferences now shows a scaled **actual Tegh interface render** for the selected side/top navigation, light/dark theme, text size and density combination. The 16 packaged WebP previews use fictional sample data; they are not a live view of the current company.
- ActivityShell assigns and cleans one current task/report scroll owner and supplies a guarded local wheel/keyboard fallback for environments where the browser exposes overflow but fails to move the assigned region. Inputs, textareas and nested scroll regions are not hijacked; Ctrl+wheel remains browser zoom.
- A final R25 presentation layer completes the selected dark theme for the newer R22/R23 work surfaces and makes real task scrollbars visible where the browser exposes them.
- Cache revision is `5990-r25`.

## Before staging deployment

1. Take a coordinated backup of the application, Schema 46 database and private document storage. Preserve server-specific configuration, uploaded documents, incident logs and secrets.
2. Confirm the existing hosted database is already Schema 46. A retained Schema 45 database still requires the protected R20 Schema 46 upgrade; do not run fresh-install SQL over retained data.
3. Extract this **complete cumulative application ZIP** into the existing application root containing `app.html`. Do not upload the separate evidence/test bundle to the public site.
4. Confirm `tegh-build.json` reports R25 / Schema 46 / cache `5990-r25`. Open a fresh tab or hard-refresh after deployment.
5. In **Tegh Preferences**, change navigation, theme, text and density and confirm the preview updates to the matching actual-image asset before Save.
6. Re-test customer/vendor invoice registers and other long reports with enough rows to overflow. Verify wheel/trackpad, visible scrollbar, Page Up/Down, Home/End, final row/totals/actions, modal close, resize and sidebar toggle.
7. Re-run representative accounting workflows in a fictional staging company: banking import/review/reconcile/interbank, AR/AP documents/payments, journal/opening balances, payroll draft/verification/posting as permitted, imports, reports/exports, invoice Send/Edit/Attachments, permissions and period locks.

## Important evidence boundary

Local R25 tests use the actual frontend source and packaged PHP functions with explicit fictional browser transport and SQLite/test adapters. They do **not** certify hosted authentication, IONOS, MariaDB concurrency, SMTP, private-storage permissions or all real-company workflows. The package-wide menu/action audit is route/render/reachability evidence, not a claim that every financial transaction was posted end-to-end.

Actual browser zoom at 125/150/200%, native scrollbar-thumb dragging/trackpad behavior, limited-role authentication and the declared 99% routine-workspace matrix remain deployed/manual acceptance gates.

## Rollback

Because R25 introduces no schema change from R24, keep the exact R24 application backup. If no server storage/schema change occurs during staging, restore the complete matching R24 managed application set and its cache revision. If data/storage/schema changes occur for any reason, restore a coordinated matching application/database/private-storage backup rather than mixing revisions.
