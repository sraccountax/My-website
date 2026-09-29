# R125 changes over R124: a friendlier dashboard

5.9.9 / Build 5990 / Schema 46. Cache token `r125-tegh`. No database migration. Wording and layout only: every figure comes from the same source as before, and every card and button opens the same screen.

## What changed

| Before | Now |
|---|---|
| No summary: you had to read four cards and a chart to know how the business is doing. | One sentence at the top, e.g. "So far this year you’ve made a profit of $4,662.61. Customers owe you $22,753.48 ($22,753.48 is overdue) and you owe suppliers $5,007.98." |
| The only way to ask a question was the small Assist icon. | An "Ask Tegh anything" box sits next to the summary and opens Tegh Assist with the answer. |
| Cards read "Cash at Bank", "Accounts Receivable", "Accounts Payable" and "Net Profit (Year To Date)". | Cards read "Money in the bank", "Customers owe you", "You owe suppliers" and "Profit this year" (or "Loss this year"). The accounting term stays in small print for accountants. |
| Card notes: "Current book balance", "25 open invoices". | "What your books show today", or a warning when the book balance is below zero. "25 unpaid invoices · $22,753.48 overdue". Amounts are coloured red, amber or green where it helps. |
| "Income Versus Expenses" showed −$1,058.83 next to a $4,662.61 year-to-date card, with no explanation. The axis read "4.6K / 1.1K / −2.3K". | "Money In and Out" with Money in / Money out / Profit (or Loss). One sentence explains the chart: "Over these 6 months you spent $1,058.83 more than you earned." The axis uses round steps with a $ sign. "Exact values and accessible table" is now "Show the numbers". |
| "Quick Actions" had a "Ctrl + Alt" label and number keycaps, scrolled inside its own box, and used system names. | "Shortcuts" in everyday words ("Bill a customer", "Enter a supplier bill", "Match bank transactions", "Record an expense", "Reconcile the bank", "Record money received", "Pay a supplier"). All of them show, with no inner scrollbar. Keyboard shortcuts still work. |
| "Tasks Requiring Attention" sat at the bottom and included items with nothing to do ("0 bills due soon"). | "To Do" sits right under the cards and only lists what needs doing: "2 bank transactions to sort out", "Customers are late paying $22,753.48: remind them", "N bills due soon" and the month-end checklist. When nothing is due it says "You’re all caught up." |

## Layout
- **Desktop:** summary and cards, then To Do, then the chart with shortcuts beside it. Sections keep their natural height and the page scrolls, so nothing is clipped or overlaps.
- **Phones and tablets:** summary and Ask box, cards, To Do, shortcuts, then the chart. Nothing scrolls sideways.

## Verified
Checked with Playwright (Chromium) at 1440×900 and 390×844.
- **Dashboard:**
  - the summary, cards, To Do, chart sentence and shortcuts show without clipping or overlap;
  - asking "who owes me money" from the dashboard opens Tegh Assist with the answer;
  - no sideways scrolling.
- **Regression:**
  - all 46 menu screens load on desktop and phone with no errors;
  - idle page activity is still 1 change;
  - the Tegh Assist panel checks from R124 still pass.

## Files
- **Changed:** `assets/tegh-portal-v5990.js` (dashboard), `assets/tegh-forecast-v5300.js` (chart sentence, axis steps, "Show the numbers"), `assets/tegh-r120.css` (section 23)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R125-CHANGES.md`
