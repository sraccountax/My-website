# R177 — New dashboard (off until you turn it on)

R177 = R176 plus a new dashboard, a read-only route that feeds it, and a switch in Site & Release Settings.
**After you install R177 nothing changes for anyone** until you turn the new dashboard on.

## Turning it on

**Site & Release Settings › Release › New dashboard**, with three choices:

| Choice | Who sees the new dashboard |
|---|---|
| Off (the default) | Nobody. Everyone keeps the classic dashboard. |
| Platform owner only | You. Use this on staging first. |
| Everyone | Every company user. |

Every save needs a reason and is kept in the change history, like the other release settings. Anyone who sees the
new dashboard can press **Switch to the classic dashboard**. The classic dashboard then shows **Try the new
dashboard** to switch back. That choice is remembered on the device.

## What the new dashboard shows

- **Cash at bank** with a 21-week line: 8 weeks of actual balances and a 13-week forecast (dashed). The lowest
  expected balance is marked, and the page says when the balance is expected to go below zero.
- **Customers owe you**, **You owe suppliers** and **Profit (or loss) this year**. These figures count up when the
  page opens. Profit has a running total for the year and a comparison with the same point last year.
- **Money in and money out** by month from your bank lines (Quarter, 6 months or 12 months). Hover over a month,
  or use the arrow keys, to read each month. **Table** shows every figure.
- **Needs you:** bank lines to sort, invoices more than 30 days late, bills due in the next 7 days, GST/HST owed or
  refundable, and whether the bank is reconciled. Each item opens the page where you deal with it.
- **Who owes you** by days overdue (your own ageing cut-offs), and **Where the money goes**: your largest expense
  accounts this year, everything else, and refunds and credits on their own line.
- The **Company feed** (R171) stays on the page.
- **A new company** sees **Get your books going** (import a bank statement, add opening balances, send the first
  invoice) and helpful empty panels instead of zeros and empty charts.
- **While loading,** each panel shows a placeholder in its own shape and fills in when the data arrives. A panel that
  cannot load says so and offers **Try again**; the others still show.
- **Light and dark:** the page follows the app's theme (Settings › Appearance). It works on phones, and motion is
  switched off for people who turn it off on their device.

## Where the figures come from

`GET dashboard/summary` is read-only (it writes nothing) and uses the calculations the app already has, so the two
dashboards always agree:

| Figure | Source |
|---|---|
| Cash, owed to you, you owe, profit this year | `workspace/summary`, the classic dashboard's source, including your Dashboard Figures accounts |
| Weekly bank balance (past) | Posted entries on the bank ledger accounts, with the same rule as the cash forecast's opening balance |
| Weekly forecast | The cash forecast (`reports/v5600/forecast`) |
| Who owes you | The receivables ageing, with the same buckets as the ageing report, plus customer opening balances |
| Profit trend, expenses | Posted income and expense accounts for your fiscal year to date |
| Money in and out | Bank lines by month, excluding lines marked as excluded (the same rule as the classic chart) |

The route answers only for people the switch allows, and only for companies they belong to. Each part is worked out
on its own: if one part fails, the others still show.

## Files

| Area | Files |
|---|---|
| Server | `api/dashboard_summary_r177.php` (new); `api/index.php` (route); `api/site_settings_r174.php` (the switch) |
| Browser | `assets/tegh-dashboard-r177.js`, `assets/tegh-charts-r177.js`, `assets/tegh-skeleton-r177.js`, `assets/tegh-dashboard-r177.css` (new; the scripts load only for people the switch allows); `assets/tegh-portal-v5990.js`; `assets/tegh-gate-v5990.js`; `app.html` |
| Cache token | `5990-r177-dashboard` |
| Database | No new tables. The switch is one more field in the existing `site_settings` row for releases. |
