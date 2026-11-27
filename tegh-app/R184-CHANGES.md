# R184: Report Builder

R184 = R183 plus the **Report Builder**: users build their own reports from their books.

**After installing, it is off.** To switch it on, go to Site & Release Settings › Release › **Report Builder** and choose:
- **Off**;
- **Platform owner only**;
- **Everyone**: every company user, in Full Accounting.

Reports only read the books. They never change them.

## Where

- **Reports › Report Builder**, at the top of the Professional Reports list.
- **Full Accounting only.** It is not offered in Guided.

## Building a report

| Step | What the user chooses |
|---|---|
| 1. What the report is about | Transactions (General Ledger lines), Sales invoices, Sales invoice lines, Bills, Bill lines, Credit and debit notes, Payments received and made, Expenses, Bank lines, Sales tax lines, Customers, Vendors |
| 2. Period | This or last financial year, month, quarter or calendar year, or any dates |
| 3. Layout | **Summary with totals**, or a **List of lines** with up to 20 chosen fields |
| 4. Rows | Up to three fields to group by, for example customer, then product. With two or more, each group gets a subtotal |
| 5. Columns side by side | Month, quarter, calendar year, financial year, or any field (for example tax code, status or bank account). Every period in the range gets a column, even an empty one. With periods, optional **Change** or **% change** columns |
| 6. Values to add up | Up to four, for example Total and Open balance, or Debit and Credit. **Number of lines** is always available |
| 7. Filters | Up to 12 conditions on any field: is, is not, is one of (picked from the company's own values), contains, blank, over, under, between, before, after |
| 8. Sort | By a row field or by the total, ascending or descending |

**Result**
- A table with subtotals and a **Total** row.
- **Select any figure** to see the lines behind it, then download them as CSV or open them as a list report.
- **Download** (Excel, CSV, PDF, Print): available to roles that can export reports today.
  - Excel and CSV keep every column side by side.
  - A report wider than 12 columns prints and saves as PDF with each row's figures listed under it, as Tegh's other wide reports do.

**Each report is sealed like the built-in reports:**
- a reference number;
- a hash of its content;
- an audit entry;
- the label "Management report — unaudited".

## Saving and sharing

- **Save:** private ("Only me") or shared with **everyone in the company**. A shared report shows each person only the data their own role allows. A viewer opening a shared report sees what a viewer may see.
- **Changing a report:** only its creator can change it. Anyone can **Save as new** to make their own copy.
- **Deleting:**
  - its creator can delete it;
  - so can a company owner or admin, for a shared report.
- **Repeated saves:** the same save sent twice saves once.
- **Edits on two screens:** an edit made on an older screen is refused, with a request to reopen the report.
- **Audit log:** saving, changing and deleting are recorded.
- **Backups:** company backups include saved reports, and restore brings them back.

## Figures

- **General Ledger** uses posted entries only, exactly like the Trial Balance. Debits and credits by account for a period equal the Trial Balance's period debits and credits.
- **Sales and purchase documents** include every status. Use the **Status** field to filter, for example to leave out drafts.
- **Currency:** amounts are in the company currency (the recorded amounts of foreign-currency documents).
- **Financial year:** columns are labelled by the year the financial year ends ("FY 2026"). A company whose year does not end on 31 December sees "FY ending Mar 31, 2026".

## Safety

- **No queries written by users.** The server builds every query from its own list of fields. Anything else, such as an unknown field, a setting or text in a field name, is refused, not ignored.
- **Values are never part of the query text.** Every value a user types is passed separately.
- **One company only.** Every query is limited to the company in use, and the values offered in pick-lists come only from that company.
- **Roles still apply.** Each data area needs the same permissions as the matching built-in report.
- **Limits.** A report is refused, never shortened, when it would have:
  - more than 60 columns side by side;
  - more than 5,000 summary rows;
  - more than 25,000 list lines.

## Testing

| What | Result |
|---|---|
| Suite `58-r184` (16 checks) | Every figure worked out by hand from the records the test enters. Details in `beta-gate-evidence/r184/RESULTS.md` |
| Canadian before/after comparison and full gate | See `beta-gate-evidence/r184/RESULTS.md` |

Not tested on the IONOS server.

## Files

| Area | Files |
|---|---|
| Server | New: `api/report_builder_r184.php`. Changed: `api/index.php` (routes), `api/site_settings_r174.php` (switch), `api/backup.php` (saved reports in backups) |
| Browser | New, loaded only when the page opens: `assets/tegh-report-builder-r184.js`, `assets/tegh-report-builder-r184.css`. Changed: `assets/tegh-portal-v5990.js` (menu, Reports list, settings card), `assets/tegh-gate-v5990.js` (module list), `assets/tegh-preflight-v5990.js`, `app.html` |
| Database | Table `report_builder_reports` is created the first time a report is saved (CREATE privilege, as R174 and R182 needed). Nothing is created while the switch is off. No existing table or row changes. Rollback: drop the table |
| Cache token | `5990-r184-builder` |

## Not in R184 (planned)

- **R185:**
  - data areas for payroll, budgets, fixed assets, products, reconciliations and the audit log;
  - simple calculated columns;
  - starter templates.
- **R186:** scheduled email of saved reports. This first needs a check that the IONOS plan can run a timed job.
