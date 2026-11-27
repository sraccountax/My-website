# R126 changes over R125: top navigation, guided mode and dark mode

5.9.9 / Build 5990 / Schema 46. Cache token `r126-tegh`. No database migration. Wording, styling and two display-count corrections; no accounting logic changed.

## How it was checked
Each mode was switched through Tegh's own Appearance screen and mode switch, as a user would.
- **A contrast checker** measured every visible piece of text against its real background, and flagged bright boxes in dark mode. It ran on all 46 menu screens, Tegh Assist, reports, and every open top-navigation menu.
- **Combinations checked:** light and dark × side and top navigation × full and guided mode, on desktop and phone.

| Check | Before | After |
|---|---|---|
| Dark mode: text or boxes hard to read | 110 problem patterns | none (the only remaining flag is the Appearance preview picture) |
| Dark mode: top navigation menus | white boxes with near-white text, unreadable | readable |
| Light mode: faint text | 1 | none |

## Dark mode
Dark mode now covers both **Dark** and **Auto** when the device is set to dark.
- **Top navigation:** the dropdown menus (Banking, Receivables, Payables, General Ledger, Payroll, More) are readable, and the search field is no longer a grey block.
- **Advanced Accounting pages** (Budgets, Fixed Assets, Recurring, Analytics, Collections): these were bright cream and white panels with light text. They are now dark.
- **Reports:** total rows (e.g. Total Expenses, ageing totals) were light bars with invisible numbers. Faint record counts, pagination and table headings are now readable.
- **Forms:** labels that were near-black on the dark background now show properly on Payments, Expense Vouchers, Upload Statement, Journal Entries, Payroll quick calculation, Recurring, bank tools and Preferences.
- **Banners and panels:** journal line headers, balance bars, bank-source boxes, notes and warning banners are dark.
- **Teal switchers:** white text on bright teal (Customer Invoice / Credit Note / Debit Note, Match / Post) now uses dark text.
- **Recent additions:**
  - dashboard summary, chart sentence and profit line;
  - Tegh Assist suggestion chips, answer box, choices and close button;
  - reconciliation cards and totals;
  - the guided "T" badge.

## Top navigation
- **Short names:** menus use the same short names as the sidebar (Match and Post, Reconcile Account, Transactions Report, Bank General Ledger, Bank Reconciliation, Invoice Register and so on), instead of the old long names.
- **"More"** replaces "More modules", and "Advanced Accounting" no longer wraps.
- **Section pages:** five cards showed the placeholder "Open this workspace and review its records." They now have real descriptions:
  - Bank Transfers;
  - Collection Drafts;
  - Customer Invoice & Note Register;
  - Period Trial Balance;
  - Document Intake.

## Guided mode
- **Money In counts drafts no longer:** the card counted draft invoices, which nobody owes yet, so it showed 26 invoices and $23,261.98 while the dashboard showed 25 and $22,753.48. It now counts only issued, unpaid invoices. The same applies to bills.
- **Bills card title:** "8 Bills Due Soon" is now "8 Bills to Pay". The card lists bills that are overdue or due in the next 30 days, which is what the old title implied but didn't say.
- **Plain wording:** "Choose the GL coding for imported transactions in Match And Post Transactions" is now "Tell Tegh what each bank transaction was for, then post it."

## Verified
- **Contrast checker:** no issues in dark mode (side and top navigation, full and guided) or light mode (full and guided).
- **Top menus:** all six are readable in both themes.
- **Regression:**
  - all 46 menu screens load on desktop and phone with no errors;
  - idle page activity is still 1 change;
  - the Tegh Assist panel checks and the 54-question plain-language test still pass.
- **Test account:** reset to light, side navigation, full mode.

## Files
- **Changed:** `assets/tegh-r120.css` (section 24 and additions), `assets/tegh-portal-v5990.js` (top-menu labels, "More", section descriptions, guided counts and wording)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R126-CHANGES.md`
