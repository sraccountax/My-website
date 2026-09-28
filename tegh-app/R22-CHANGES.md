# R22 compact-workspace changes

## Shared layout

New ActivityShell owns the 56px global navigation, compact activity toolbar, remaining-height workspace, reserved action/summary regions and responsive tables. Expanded sidebar labels receive independent tracks; collapsed rail is 72px. Top navigation uses a module selector in the existing global bar rather than adding another row. Desktop tables retain exact amounts; narrow and intrinsically wide tables expose labelled stacked cells rather than a lateral scrollbar.

Six historical stylesheets are consolidated into a named legacy CSS layer, removing their 9,049 legacy priority overrides in the combined copy. Original files are retained unchanged. The unlayered Activity CSS owns component geometry. The hidden attribute still has an explicit semantic priority rule. This is not a framework or accounting-engine rewrite.

## Operational families

Report headers/filters, invoice registers/details/editors, bills/payments, journal/opening grids, bank review/reconciliation, imports, payroll run/employee workspaces, directories, intake/collections and advanced registers use authored task layouts. Long explanations become contextual help while accounting consequences/unverified balance warnings remain available. Large grids have actual scroll owners; genuine comparison panes can scroll independently. Narrow and enlarged-content layouts prefer reachable reflow over forced fixed-height fit.

Financial Analyst graphs redraw to their actual container width, retain signed values, and expose exact monetary amounts on keyboard focus or pointer interaction. Missing forecast values remain unavailable rather than zero.

Actual controls are moved, not cloned; their existing APIs, permissions and handlers remain. New Pay Run renders all eligible employees and explains the existing 250-employee selection boundary, rather than silently truncating the directory. Journal add/remove preserves unsaved amounts. Invoice/P&L R20 and opening-confirmation R21 behavior are retained.

## Large report and import safeguards

Generic reports above 1,000 report lines use 250-line on-screen pages with First/Previous/Page/Go/Next/Last and explicit full-result counts. The immutable full model is retained for exports. Group continuation labels and original totals remain; pagination does not recalculate accounting. Day Book avoids building a duplicate legacy 10,000-row DOM before the authoritative viewer.

Import preview keeps complete logical records, source rows, selection scope and explicit approval. One true modal content scroller exposes every row with reserved footer actions. Multiline rowspan labels remain accurate when stacked. Search starts with matching records expanded but still permits deliberate collapse/expand; selection continues to apply to the complete record.

## Boundaries

No PHP/SQL accounting or schema code changed from verified R20 Schema 46 + R21. No new backend migration is required from Schema 46. Local tests are explicit fictional frontend transports and SQLite service adapters, not deployed authentication, MariaDB locking or SMTP certification. The entire 99% routine-workflow matrix is not signed off. Core Chart of Accounts, full Guided/limited-role routing, actual browser zoom, full startup, real datasets and deployed-runtime approval remain separate gates.
