# R192: Functional and UI audit of the Canada and India editions

R192 is R191 plus the fixes below. They came from a screen-by-screen audit of both editions on the test server:
- **What was opened:** every menu screen, its first New/Add/Create form, the settings and tool pages, and every report.
- **Where:** a Canadian and an India company, at 1440 px and 390 px.
- **What was recorded:**
  - browser errors and failed server calls;
  - page overflow, controls off screen and cut-off text;
  - unlabelled fields;
  - text such as "undefined" or "NaN";
  - the other edition's wording.

Schema stays 46. There is no migration.

## 1. India edition

### Company details
- **The fault:** when the time zone and the registrations were changed in the same save, the new registrations were dated "today" in the *old* time zone. They were then read back in the *new* zone. When the two zones were on different dates, Company Details showed the previous registrations until the next day.
- **Now:** the new version is dated in the company's new time zone, and the reply is read in that zone too.

### Financial year wording and dates
- **Period lists** (Trial Balance, Day Book, General Ledger, AR/AP trial balance and others):
  - they say "Financial year to date" and "Previous financial year";
  - their dates are shown as DD-MM-YYYY.
- **Other shared screens:** text written for Canada that says "fiscal year" or "GST/HST" reads "financial year" and "GST" in an India company. Typed values are never changed.

### Payroll pages
- **Removed:** India Pay Runs, Salary Structures and Payroll Registers no longer show the Canadian "Payroll Support Tool" notice (CRA T4127, Quebec).
- **No Canadian payroll call:** these pages no longer call the Canadian payroll service, which refused the call for an INR company.

### Screens for Canadian rules
These screens now open a short page saying they are for Canadian companies, with links to India Masters, India payroll and India Home:
- Payroll updates (CRA);
- the Canadian payroll screens;
- Tax codes and the Tax Code Report;
- Sales tax rates;
- GIFI Mapping and the GIFI Report.

This applies however the screen is reached: search, a link or Tegh Assist.

### Recurring documents
- **The problem:** recurring documents do not apply India GST codes, yet the forms offered "Apply GST/HST" and "Apply PST".
- **The forms:** in an India company the options are replaced by a note to create the GST invoice from Customer Invoices or Vendor Invoices.
- **The server:** it refuses a taxable recurring invoice line or a recurring vendor invoice with GST/HST or PST in an India company (422 `india_recurring_gst`).

Canadian companies are unchanged.

### Expenses
**Expense page:**
- it shows a GST column and no PST column;
- the PST note is replaced by: GST on an expense is recorded through a vendor invoice.

**Expense Register report:**
- it has a GST column;
- it has no PST column or PST total.

**Tax code field on bills:** its hint names CGST and SGST, or IGST, instead of PST.

### Data import
In an India company:
- **States:** a customer or vendor state is checked against Indian states and union territories. Before, it was checked against Canadian provinces, so every Indian state was refused.
- **Country:** a blank country becomes India. Before, it became Canada.

### "State" instead of "Province"
- the report builder's region field;
- Tegh Assist's Create Customer form.

## 2. Both editions
- **Match and Post on a phone:** the table was squeezed to the screen width, so dates, amounts and descriptions were cut off ("-$1,149…"). On screens up to 820 px it now keeps readable column widths and scrolls sideways inside its frame. The Status column is wide enough for its heading. Desktop is unchanged.
- **Report scope line:** the line beside a report read "Expense registerJan 1, 2026 to Nov 21, 2026". It now reads "Expense register · Jan 1, 2026 to Nov 21, 2026".
- **Match and Post Actions menu:** on a phone, the table now scrolls sideways. Scrolling it closed an open Actions menu, even when the scroll had just brought the button into view. The menu now moves with its button and closes only when the button leaves the screen. This was found by the R186 phone test in the first R192 gate.
- **Users:** each member's role list has an accessible name ("Role of …").

## 3. Shared code (additive)
- `require_company` records the open company's edition. Only the report builder's India label reads it.
- `TeghPortal.isIndiaEdition()` and `TeghPortal.listReports()` are exposed. The advanced screens and the audit use them.

## 4. Tests
- **New suite:** `70-r192` checks every fix above in both editions. It covers:
  - the API, including the time-zone case, which uses two zones that are always on different dates;
  - the browser at 1440 px and 390 px.
- **Audit sweep:** `audit-sweep.mjs` is a reusable check that opens every screen.
- **Where:** both are in `india-edition/tests/`.
