# R127 changes over R126: bank balances in Match and Post

5.9.9 / Build 5990 / Schema 46. Cache token `r127-tegh`. No database migration. Display only: nothing is posted or changed.

## What changed
The **Bank Transactions** panel in Match and Post now shows a balance row for the selected bank account and dates, under the title:

| Opening · From date | Money in | Money out | Bank balance · To date | In your books | Difference |
|---|---|---|---|---|---|
| balance before the first day | imported deposits | imported withdrawals | opening + in − out | General Ledger balance on the To date | shown with **Reconcile →** when not zero, otherwise "✓ Agrees with your books" |

- **Where the numbers come from:** the account's opening balance plus every imported statement line up to the date. Lines marked duplicate or excluded are left out. This is the same calculation Reconcile Account uses (R122), so the two screens always agree.
- **More detail:** hovering over the row explains how it is calculated and how many statement lines were used.
- **Reconcile:** opens Reconcile Account for the same account and dates.
- **Updates:** the row refreshes when you change the account or dates, and after each reload of the list (e.g. after posting). Switching between Post and Match reuses the figures without asking the server again.
- **Layout:** it sits on its own row on desktop and phone, with readable colours in light and dark mode.

## Verified
- **Test company, Jan 28 – Sep 28, 2026:** $10,000.00 + $5,510.58 − $16,214.78 = −$704.20. For Sep 1 – Sep 28: −$441.32 + $4,103.95 − $4,366.83 = −$704.20. Both match Reconcile Account.
- **Regression:**
  - all 46 menu screens load on desktop and phone with no errors;
  - idle page activity is still 1 change;
  - the dark-mode contrast check on Match and Post is clean.

## Files
- **Changed:** `assets/tegh-portal-v5990.js` (Match and Post panel), `assets/tegh-r120.css` (section 25)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R127-CHANGES.md`
