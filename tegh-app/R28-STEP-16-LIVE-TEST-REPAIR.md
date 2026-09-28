# R28 Step 16 — Live-Test Repair

This cumulative release implements coding items C01–C15 from the Step 15 live-test report. It is built from the exact Step 15 deployment package (SHA-256 `b2aa8bee5593eb3c1177104929a27b6c48ed27f531b005a362b505a5f7e3501a`) and retains Schema 46.

## Implemented

- Stable report scroll owners and reusable register scrollers; programmatic report scrolling uses immediate behavior.
- One scrollable Professional Reports catalogue with unclipped groups and no duplicate heading.
- A dedicated Guided Home layout and semantic light/dark surfaces.
- Explicit Profile, Appearance and Sign out routing, including a bounded logout request and cross-tab logout notice.
- True module landing pages for Receivables, Payables, General Ledger and Payroll.
- Cash Flow table-height protection, compact exception disclosure and reconciliation placement.
- Authored single-row bank-report filters and content-sized bank account rows with exact Cancel restoration.
- Compact customer, vendor and invoice form sections without injected numbering/completion badges.
- Revision-scoped safe-page caching, pre-mount loading state and synchronous canonical finalisation.
- Global-search query preservation across background render events.
- Canonical customer/vendor invoice-register routes and a Settings shortcut to Reports Centre.
- One 240 px expanded / 72 px collapsed desktop sidebar geometry with the middle navigation as scroll owner.
- Dashboard forecast request-generation guards, visible retry state and request-reference diagnostics.

## Integrity and acceptance status

No database migration is required. Accounting, books, payroll, payments, imports, interbank, invoice-document and schema control files remain byte-identical to the Step 15 source package. Local verification covers JavaScript syntax, source contracts, release metadata and protected-file hashes. Authenticated hosted interaction, visual, keyboard, dark-mode and real accounting regression acceptance remain pending after deployment; this package does not claim those tests were run locally.

