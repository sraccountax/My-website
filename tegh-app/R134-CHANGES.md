# R134 changes over R133: charts on the client dashboard

5.9.9 / Build 5990 / Schema 46. Client page version `5990-r134`. No database migration.

## What changed
The client's view-only dashboard now has animated charts, and **the set of charts follows the reports the accountant shared**. Each shared report that has a natural picture adds one chart card:

| Shared report | Chart |
|---|---|
| Profit and Loss | Money in vs money out by month (columns) with a profit line, on one axis |
| Cash Flow Statement | Net cash per month: blue when more came in, red when more went out |
| Accounts Receivable Ageing | Who owes you, by how late (Not due, 1–30, 31–60, 61–90, 91+ days) |
| Accounts Payable Ageing | What you owe suppliers, by how late |
| Customer Invoice & Note Register | Invoiced each month |
| Vendor Invoice & Note Register | Supplier bills each month |
| Expense Register | Spending by category (top 5 + Other) |
| Balance Sheet | What the business owns, owes and the owner's share |

Trial Balance, General Ledger, Bank Reconciliation and GST/HST Summary add no chart; GST/HST already has its own summary card. If none of the shared reports has a chart, the dashboard shows just the summary cards. Changing a link's reports changes the charts the next time the client loads the page.

Each chart card has:
- **Headline:** a plain one-line result, such as "Loss so far this year: $4,461.78" or "Customers owe $25,483.73 · all overdue".
- **Legend:** shown when the chart has two or more series.
- **Tooltip:** on hover or keyboard focus; the Profit and Loss tooltip lists money in, money out and profit for the month.
- **Table:** a button that switches the chart to a table of the same figures.
- **Open report →:** jumps to the full report.

**Motion:** the summary sentence, the stat cards and the charts rise in one after another. Stat figures count up. Columns grow from the zero line, bars grow from the left, and the profit line draws itself as each chart scrolls into view. All of this is switched off when the device asks for reduced motion.

**Colours:** the colours were checked with a colour-blindness and contrast validator for both the light (white) and dark (#15231f) surfaces. In light mode the green profit line is below 3:1 contrast, which the validator accepts only with a legend and table view; both are there. The whole dashboard follows the device's light or dark setting.

## Also fixed
- **Subtotals:** the client's report tables now show subtotal rows for Ageing and Cash Flow. Those reports return plain-number group totals, which were skipped before.
- **Audit History:** chart loads are not written to Audit History one by one; the client's sign-in already records the visit. Opening a report from the Reports tab is still recorded.

## Verified
Tested with Playwright (Chromium) at 1440×900 and 390×844, light and dark, with a real emailed code through a local SMTP catcher.
- **All 8 chart reports shared:** 8 cards drawn. The figures match the report totals (ageing buckets add up to $25,483.73), with no sideways scrolling.
- **Profit and Loss + Balance Sheet only:** 2 cards.
- **Trial Balance + General Ledger only:** no chart section.
- **Tooltip:** for April it shows $2,033.84 money in, $4,358.57 money out and −$2,324.73 profit.
- **Table view:** shows 9 months and switches back to the chart.
- **Reduced motion:** charts draw at once, with no animation.
- **Earlier checks:** the R133 API and security tests still pass. The report view is still audited, chart loads are not.

## Files
- **New:** `assets/tegh-client-view-charts-r134.js`, `R134-CHANGES.md`
- **Changed:**
  - `assets/tegh-client-view-r133.js` (charts, count-up, subtotal fix)
  - `assets/tegh-client-view-r133.css` (chart styles)
  - `client-view.html` (loads the chart script)
  - `api/client_view_r133.php` (chart loads not audited)
