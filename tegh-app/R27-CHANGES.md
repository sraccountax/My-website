# Tegh R27 — UI stability, compact reporting and dark-mode staging

R27 is a cumulative staging candidate built only from the exact R26 archive whose SHA-256 is `507e436a9f6b9cc6fe0a78690fec088d1707717987d271c2869b10509134efe3`. It remains Tegh 5.9.9 / Build 5990 / Schema 46 and introduces no database migration.

## Implemented

- The application root is constrained to the viewport. Operational pages, tables, lists, reconciliation panes, dialogs and account selectors use explicit internal scroll owners with sticky table headers. The active activity module no longer intercepts wheel events.
- Bank Reconciliation now presents genuine 38px one-line rows with selection, date, truncated accessible description/reference, amount and status. Bank Statement and Books lists scroll independently. Match and Post & Match remain separate operations.
- Workspace search has one canonical Ctrl/Cmd+K owner, focus-once behavior and editable-input protection.
- Customer and vendor invoice registers retain one shared presentation contract, internal horizontal overflow for wide selections, stable selection geometry, dropdown-only actions and no expanded “More details” records.
- Quick Actions order/selection is synchronized through the existing authenticated interface-preference API. Local storage is a cache rather than the authority.
- Day Book groups default collapsed and expose date, voucher, source type, description, authoritative counterpart GL summary, debit, credit and status. Bank transactions exclude the source bank account when deriving the counterpart; invoices and bills prefer their authoritative income/expense journal lines.
- Expense, payroll and General Ledger outputs no longer display internal UUIDs as ordinary references.
- Normal report KPI cards are consolidated into compact authoritative totals footers; permanent details/provenance and routine explanatory copy are removed while warnings and unavailable evidence remain.
- Professional Reports uses one compact searchable/filterable catalogue toolbar and compact category sections.
- Routine customer, vendor, product/service, invoice, bill, expense, payment, employee and bank-account forms receive numbered keyboard order, Required markers and Pending/Complete state in a responsive 3/2/1-column grid.
- Hover/selection geometry is stabilized, sign-out uses a truthful transition without stale company data, and redundant visible Search headings are made visually hidden while remaining accessible.
- Semantic Light/Dark/Auto tokens cover core surfaces, tables, forms, menus, dialogs, overlays, reports, reconciliation and marketing pages. The existing server preference path remains the sole theme authority.
- Cache revision is `5990-r27`. No asset was deleted and no preload/cache-server change was made without runtime evidence.

## Preserved

All R20–R26 accounting and workflow controls remain in the cumulative tree, including Schema 46, company isolation, role permissions, period locks, idempotency, invoice Send/Edit/Attachments, manual opening-balance confirmation, opening-control posting restrictions, interbank lifecycle controls, ageing filters, column ordering and transaction continuity.

## Verification boundary

Active JavaScript parsing and 30 R27 source-contract checks pass. A fresh static graph covers the application, dynamic imports, CSS URLs, marketing pages and the v211 statement-import chain. Actual browser rendering/input events, PHP/MariaDB execution, accounting-total parity, export-file inspection, CRA payroll comparison and authenticated IONOS acceptance are explicitly **NOT_TESTED** in this environment. See `verification/`.

This package is not production sealed.
