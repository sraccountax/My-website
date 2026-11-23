# R193: Audit with large companies, both bookkeeping modes, light and dark themes, side and top navigation

R193 is R192 plus the fixes below. They came from an audit on the test server with two large synthetic companies:
- **Canada:** Volume Northwind (5,000 invoices, 2,000 vendor invoices, 3,000 bank lines, about 12,300 journal entries).
- **India:** Volume Deccan, new in R193 (6,000 GST invoices across nine states at 5, 12, 18 and 28%, 2,000 purchase invoices, 120 credit notes, 150 TDS deductions, 5,200 payments, six months of payroll for 40 employees, 3,000 bank lines).

**Screen matrix (24 runs):** every menu screen, its first New/Add form, the settings and tool pages and every report, in:
- both editions;
- both bookkeeping modes (Guided is Canada only, see section 3);
- light and dark themes;
- side and top navigation;
- 1440 px and 390 px.

**Figures:** the reports of both large companies were checked against totals summed independently from the generated data. The customer and vendor balances of every company on the test server were checked against the GL.

Schema stays 46. There is no migration.

## 1. Both editions: customer and vendor balances agree with the GL

**The problem:** the customer and vendor balance report (AR/AP Trial Balance) could disagree with the receivables and payables control accounts. It left out or miscounted:
- posted credit notes;
- refunds to a customer (or from a vendor), which were left out;
- an amount paid beyond its invoice, for example a paid invoice later corrected lower: only the part applied to the invoice was counted;
- old bank matches that had since been unposted, which were still counted;
- India only: TDS deducted from a vendor invoice.

**The fix:** each payment now counts what its own entry posted to the control account, and credit notes and (India) TDS are included.
- **Old bank matches:** an unposted match no longer counts.
- **Receivable Ageing, Payable Ageing, Customer Balances and Vendor Balances:** now include TDS and the amount beyond an invoice, shown as an unapplied credit. Before, they disclosed the difference as an exception.

**Result on the test server:** before the fix, 17 accrual-basis companies disagreed (10 Canadian, 7 India). Now all 86 accrual-basis companies in both editions agree.
- **Cash-basis companies (4):** not compared; they post no receivables or payables by design.
- **One R142 legacy test company:** its balances agree (both zero), but it has no receivables or payables control account, so its ageing and balance reports correctly say they cannot be reconciled.

GL entries are unchanged; only the reports are corrected.

## 2. Both editions: large companies

**Workspace memory:** every invoice carried a full copy of its invoice template, which would include any logo.
- **What went wrong:** the large India company's workspace sometimes ran out of PHP memory (HTTP 500).
- **Now:** each template is sent once and restored on every invoice in the browser (portal, sign-in gate and core screens), so screens see the same data.
- **Size:** the workspace is 3 MB smaller for both large companies.
- **Memory limit:** the workspace request asks for 256 MB where the host allows it.

**Reports over the complete-row limit (25,000 rows):**
- **What went wrong:** the Day Book (default "All time") and General Ledger Detail (fiscal year to date) opened on an error for a large company.
- **Now:** the message offers the latest month of the period. Choosing it changes the period fields and loads that month in full.
- **Preview totals:** the quick preview behind a report holds only the latest 250 ledger lines. Its totals were still shown, for example debits different from credits. They are now hidden whenever the complete report replaces the preview, or is refused.

## 3. India edition: Guided mode

An India company uses Full Accounting (IN-1). Choosing Guided said "Guided mode is now active" while nothing changed.
- **Now:** it says Guided mode is not available for India companies yet.
- **The Bookkeeping Mode page:** shows Guided as not available.

Canadian companies switch as before.

## 4. Dark theme

There is a new stylesheet, `tegh-dark-r193.css`. It is loaded last and applies only in the dark theme; the light theme is unchanged.

**Panels that stayed white become dark:**
- the Add bank transaction header and footer;
- the budget drawer;
- CRA rates result;
- integrity items;
- the opening-balance note;
- the bulk-action bar;
- bookkeeping mode cards;
- the month-end confirmation;
- status pills;
- FAQ and GIFI rows;
- Match and Post secondary buttons;
- the Actions and download menus;
- the loading placeholder.

**Text that was unreadable now has at least 3:1 contrast:** labels, Journal Entry tabs, page headings, eyebrows, links, chart legends and zero amounts.

**Not changed:** the invoice template preview stays white (it shows the printed page).

## 5. Release token
`5990-r193-audit` (app.html, .htaccess, the gate and preflight scripts).

## 6. Tests
- **`71-r193`:** a new gate suite that checks every fix above.
- **`india-edition/tests/`:** holds the India large-data generator and its report check (`seed-in.mjs`, `check-in.mjs`), the all-company balance check (`party-tieout.mjs`), and the audit sweep (`audit-sweep.mjs`). The sweep gained:
  - bookkeeping mode, theme and navigation options;
  - a text-contrast check;
  - a light-panel check for the dark theme.
