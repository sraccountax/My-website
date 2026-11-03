# R121 changes over R120

5.9.9 / Build 5990 / Schema 46. Cache token `r121-reconcile`. No database migration.

R121 comes from an end-to-end functional test with complex sample data, plus a review of downloaded report layouts. The test covered:

- **Bank statement:** a 46-line statement imported, posted, matched and reconciled.
- **Customers and vendors:** receipts and vendor payments taken from the bank feed.
- **Payroll:** setup, employees, a pay run through verification and posting, and a CRA remittance.
- **General ledger and advanced:** journals, expense vouchers, fixed assets and depreciation, budgets, and recurring invoices.

## Banking and reconciliation

- **Bulk posting (Match and Post):**
  - Select several unposted lines, for example all nine monthly bank fees, and post them to one GL account in one step.
  - Select Visible now selects every visible line instead of just one.
  - Each line still gets its own journal entry, with its bank description as the memo.
  - The posting preview totals every selected line.
- **Refunds and reversals:** a deposit to an expense account, such as a bank fee reversal or a supplier refund, was rejected with "an explicit contra decision is required", and the screen had no way to give it. A confirmation checkbox now appears when a selected line runs opposite to the account's normal direction.
- **Exclude from books:** duplicate or non-business lines can be excluded from the posting panel. "Review excluded lines" opens the list where they can be restored. Before, excluding was only possible on a screen with no menu entry.
- **Match exact pairs:**
  - Every line posted from the bank (directly, or through a receipt or payment) has to be matched to its own GL entry before the reconciliation can complete. That was one click-pair per line.
  - Match Transactions now offers **Match N exact pairs**. In the test, 45 lines were matched in one click.
- **Reconcile Bank Account:**
  - The reconciliation screen had no menu entry and was reachable only through search. It is now under Banking > Activity.
  - **Complete Reconciliation** stays disabled, with a tooltip, until every item is matched and the difference is $0.00. The server already refused an unbalanced reconciliation.
- **GST/HST and PST remittances:**
  - A payment to CRA, or a refund from it, could not be recorded anywhere: 2100/2110 are protected control accounts, and manual journals exclude them.
  - The posting panel's account list has a new **Sales tax remittance or refund** group. It posts only Dr 2100 (or 2110) / Cr bank, or the reverse for a refund, and nothing else changes on those accounts.
  - Server side, this is an explicit `salesTaxSettlement` decision (`api/accounting.php`, `api/bank_operations_v5980.php`) that requires the company to be registered for that tax.
- **Statement preview:**
  - A blank running-balance cell was read as $0.00, so entering an opening balance for a statement without a Balance column failed with "Running balance does not reconcile".
  - When the statement has its own Balance column, the opening and closing balances are now read from it, so continuity is checked without typing them (`api/operations.php`).
  - The preview window also sizes to its content instead of leaving a large blank area.
- **Receipt and payment bank pickers:** they now show the statement reference, so three identical "$113.00 BLUE YONDER" deposits can be told apart. The "Match bank transaction" tab now shows as active and opens the search.
- **Match and Post layout:**
  - Dates are no longer cut off.
  - The posting form has inner padding.
  - The duplicate floating "rows selected" bar that covered rows is gone.

## Bugs found by functional testing (also present in R118)

- **Payroll > Add Employee:** Save did nothing. The handler looked for `button[type=submit]`, but the button had no type. The same lookup is fixed in two other forms (`assets/tegh-portal-v5990.js`).
- **Budgets:** after opening the editor or saving, the page header, search box and "Create Budget" were duplicated, and stale copies showed "0 of 0 budgets" (`assets/tegh-activity-r22.js`).
- **Budget names:** right-aligned as if they were amounts, because the header word "Budget" marked the column as numeric. A column is now right-aligned only when its values look like amounts.
- **Fixed assets:** new schedules defaulted to "1070 Interbank Transfer Clearing". They now default to 1500 Equipment, and the clearing account is excluded (`assets/tegh-advanced-v5800.js`).

## Layout

- **Desktop tables no longer collapse into cards.** A table wider than its container turned into stacked cards on a 1,440px screen, which clipped values (Bank Reconciliation Report). Cards are now used only in containers under 640px. Wider tables keep their columns and scroll sideways, as asked for when zoomed in.
- **Letter-by-letter wrapping:**
  - Table cells no longer break words one letter per line. Payroll Verification showed "Pri / ya / Sh…".
  - The Payroll Verification entry fields are narrower, so the whole table fits.
- **Forms:**
  - Optional and required labels line up in the same row.
  - Checkboxes sit inline with their text.
  - Advanced Accounting editors (Recurring Transactions and others) use two columns instead of one long column.
- **Fixed Assets / Budgets:** cards are full width (R120's `align-items:start` had shrunk them), with no empty filler.
- **Invoice detail:** amount headers are right-aligned over their values, and Subtotal / Tax / Total sit under the Amount column.

## Downloaded reports (PDF / Excel)

- **Dates:** date columns always fit on one line. "Jan 6, / 2026" had doubled every row, and the Customer Invoice & Note Register PDF drops from 5 to 3 pages.
- **Totals:**
  - Reports end with one **Total** row aligned under their amount columns, in both the PDF and Excel. The trial balance used to list six stacked "Report total · …" lines.
  - The invoice & note registers now carry totals in their PDF and Excel exports.
  - CSV stays raw data.
- **Group subtotals** keep their original case ("Total · SCN-003 · Posted", not "Total scn-003 · posted").
- **Invoice PDFs:**
  - From, Bill to and Invoice details sit side by side.
  - Dates read "Jan 6, 2026".
  - Balance due shows its currency.

## Verified

Checked with Playwright (Chromium) against a local PHP 8.4 + MariaDB copy.

- **Bank statement:**
  - A 46-line statement was imported with opening $10,000.00 and closing $1,058.75.
  - 45 lines were posted: bulk groups, receipts matched to 11 invoices and debit notes (including 2 partial payments), 6 vendor EFTs, and a fee reversal as a contra entry.
  - A duplicate POS line was excluded, then restored.
  - 46 lines were matched.
  - The reconciliation was blocked at a -$86.74 difference and then completed at $0.00.
- **GST/HST remittance:** posted Dr 2100 $412.50 / Cr 1000.
- **Payroll:** 2 employees, and a pay run with overtime was created, verified, finalized and posted to the GL. A CRA payroll remittance was recorded.
- **Other modules:**
  - A journal, an expense voucher (with $6.50 tax extracted from $56.50), a fixed asset with depreciation posted, a budget, and a recurring invoice run (INV-1031) all worked.
- **Screens:**
  - All 46 menu screens were checked at 1440×900 and 390×844, with no errors and no sideways drift.
  - Idle DOM activity is still 1 change across all screens.
- **Earlier releases:** R119/R120 tabs, the note form and the combined registers still pass.
- **Exports:** PDF, Excel and CSV were exported and inspected, including an invoice PDF.

## Files

- **Changed:**
  - `api/accounting.php`
  - `api/bank_operations_v5980.php`
  - `api/operations.php`
  - `app.html`
  - `assets/tegh-activity-r22.js`
  - `assets/tegh-advanced-v5800.js`
  - `assets/tegh-gate-v5990.js`
  - `assets/tegh-pdf-v5990.js`
  - `assets/tegh-portal-v5990.js`
  - `assets/tegh-preflight-v5990.js`
  - `assets/tegh-professional-output-v5990.js`
  - `assets/tegh-r120.css`
  - `assets/tegh-reference-r29.js`
  - `assets/tegh-registers-r23.js`
- Upload them together; the cache token must change for browsers to fetch the new scripts.

## Known limits (not changed)

- A GST/HST remittance is booked against 2100 as one amount. It does not split the payment between 2100 and 1100 (ITCs).
- The CRA payroll remittance form does not prefill amounts from posted pay runs.
