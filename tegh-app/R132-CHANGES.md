# R132 changes over R131: tax summary on the dashboard

5.9.9 / Build 5990 / Schema 46. Cache token `r132-tegh`. No database migration.

## What changed
The dashboard has a fifth summary card for sales tax, next to Money in the bank, Customers owe you, You owe suppliers and Profit/Loss this year.

- **Title:** "GST/HST you owe", or "GST/HST refund due" when you have paid more GST/HST on purchases than you collected.
- **Amount:** net GST/HST, which is GST/HST collected (account 2100) minus GST/HST paid on purchases (account 1100).
- **Details line:** "Collected $… · paid on purchases $…". It adds the net PST position when the company uses PST (accounts 2110/1110).
- **Colour:** amber when tax is owing, green when a refund is due.
- **Opening the report:** the ↗ button opens the GST/HST Summary report.
- **Layout:** on screens 1280 px and wider the five cards sit in one row. On narrower screens the tax card spans the full width below the others.

**Server:** the workspace summary now returns `taxSummary`, with GST/HST collected, recoverable and net amounts, plus PST payable and recoverable. The existing `taxPayableCents` field is unchanged.

## Verified
Tested at 1440×900, 1280×800, 1100×800 and 390×844.
- **Test company:** the card shows "GST/HST you owe $169.62 · Collected $169.62 · paid on purchases $0.00", which matches the ledger balances (2100 credit $169.62, 1100 $0.00).
- **Report link:** it opens the GST/HST Summary report.
- **Regression:** all menu screens load on desktop and phone with no errors or sideways scrolling; idle page activity is 1 change; the Tegh Assist test set passes.

## Files
- **Changed:** `api/workspace_summary_v5610.php`, `assets/tegh-portal-v5990.js`, `assets/tegh-r120.css` (section 29)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R132-CHANGES.md`
