# R28 Step 12A — Bank Reports And Accounts

## Outcome

- Banking opens a familiar Activity and Reports workspace.
- Activity contains Upload Statement, Match and Post Transactions, and Manage Bank Accounts.
- Reports contains Bank Transaction Report, Bank General Ledger Report, and Bank Reconciliation Report.
- Transfers is no longer advertised in Banking. The existing underlying transfer accounting code remains intact for backward compatibility.
- Upload Statement shows Import Transactions and Bank Statement Converter together on desktop and stacked on narrow screens.
- Manage Bank Accounts is an account directory with account-specific Opening Balance, View Bank Transactions, View Posted Transactions, and Actions controls.
- View Posted Transactions resolves the bank account to its linked General Ledger account and uses the existing posted-ledger loader. It does not reinterpret imported bank rows as a ledger.
- Bank report tables fit the available width, keep actions accessible, scroll vertically, and use expandable details instead of horizontal clipping.
- Bank transaction and bank General Ledger reports use a fixed 50-row page for predictable performance.
- Ledger search is find-and-highlight navigation across the complete result; it does not remove movements or recalculate running balances.

## Accounting Safety

- No database migration or schema change.
- No change to posting, matching, journal, tax, invoice, bill, payment, payroll, voiding, or reconciliation calculations.
- `tegh_bank_mutate()` is byte-identical to the Step 6 baseline.
- The only server changes add read-only report definitions, validated filters, linked-account resolution, and statement-evidence fields.
- Missing statement evidence is shown as Not Available; reconciliation dates are not relabelled as statement dates.

## Verification

- Retained R27 verification: PASS.
- Retained R28 Step 06–11 verification: PASS.
- R28 Step 12 contract verification: 35/35 PASS.
- Syntax sanity: PASS.
- 2026 CRA payroll reference vectors: PASS.
- 25,000-row banking simulation: PASS; 500 pages at 50 rows; complete-ledger find preserved balances.
- Authenticated cloud-browser acceptance against deployed Step 12: 50 bank rows and complete searchable book entries were visible; Post and Match selection states were correct; account-specific reports, complete-ledger find, importer/converter panels, customer/vendor registers and the compact icon-and-label sidebar were verified; desktop tables reported `scrollWidth === clientWidth`.
- The Step 12 acceptance build uses cache revision `5990-r28-s13-premium-compact-shell`, including the corrected Banking Activity description with no Transfers reference.
