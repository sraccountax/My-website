# R124 changes over R123: a simpler Tegh Assist

5.9.9 / Build 5990 / Schema 46. Cache token `r124-tegh`. No database migration. Layout and wording only: the actions, permission checks and confirmations are unchanged.

## What was confusing

| Before | Now |
|---|---|
| A large green box ("Good Evening, Owner. Your workspace and selection stay open while you review.") took a quarter of the panel and stayed on screen. | One line under the title: "Ask a question or tell me what you want to do." A short "Using the 3 bank lines you selected" note appears only when a selection is being used. |
| Answers started with an "Analysis complete" badge, a table of account codes and a request reference. | Answers start with a plain sentence, such as "You made a profit of $4,662.61", coloured green, amber or red. The table is under "See the details". |
| "Choose the command you meant." listed internal jobs with technical descriptions (e.g. "Run Accounts Payable Agent"; "Use the Tegh Journal Copilot to infer and preview a genuine GL adjustment"). | "Did you mean…" lists at most four everyday choices, each with a one-line plain hint, plus "None of these? See everything I can help with". |
| Forms showed technical fields (mode, preset, count, unit), odd labels ("Billingaddress") and a "Continue" button. The name typed in the question was ignored. | Technical fields are filled in behind the scenes. Labels are plain ("Billing address", "Supplier", "From", "To"). Buttons say what happens ("Show me", "Open …", "Next"). "add customer Bob Smith" fills in the name. |
| "Browse actions" was a flat list of about 170 entries, each with "Review action" and "Pin" buttons. Clicking one showed a card that needed "Continue". | "See everything I can help with" groups entries into Money in, Money out, Banking, Reports, Payroll, Month-end, Documents and Settings. One click answers or opens it; a small ☆ adds it to Quick Actions. |
| "Ready to help" and "Recent activity" boxes showed filler text and "Previous action". | Your last five questions appear as "Recent" chips, stored only in this browser. The answer area appears only when there is an answer. |

## Plain summaries
Each report answer starts with a one-line summary:
- **Profit & Loss:** "You made a profit/loss of …", with money earned and costs.
- **Balance Sheet:** "Your business owns … and owes …", with equity.
- **Receivable and Payables Ageing:** "Customers owe you … in total" or "You owe …", and how much is overdue.
- **Bills due and expected payments:** how many are due and how much.
- **Supplier payment history:** "You paid suppliers …".
- **Cash Flow:** "Your cash went up/down by …".
- **Cash Forecast:** "You’re expected to have about … in the bank by …", with a warning if cash may drop below zero.
- **Trial Balance:** whether the books balance.
- **Payroll readiness** and **duplicate bank lines:** a one-line status.

Dates read "Jan 1, 2026 – Sep 28, 2026" instead of "2026-01-01 to 2026-09-28". Report answers also offer **Open full report** and **Change dates**.

## Other touches
- **Every answer** repeats your question ("You asked: …").
- **Wording:** "Thinking…" while working, "Cancelled. Nothing was changed." when you cancel, and guide steps read as actions ("Record the expense →").
- **Phones:** the question box, Ask button and a sideways-scrolling row of suggestions all fit on one screen.

## Verified
Checked with Playwright (Chromium) at 1440×900 and 390×844.
- **Reports:** "am i making money this year", "who owes me money" and "will i run out of cash" each show the plain summary, the details, Open full report and Change dates.
- **Choices:** "create a journal entry for depreciation" shows three plain choices.
- **Forms:** "add customer bob smith" shows the form with "Bob Smith" filled in.
- **Guide and unclear input:** plain answers and the not-understood message still appear.
- **Everything list:** the grouped list opens the screen you pick.
- **Bank screen:** "Explain the difference" still works from Match and Post.
- **Regression:**
  - All 46 menu screens load on desktop and phone with no errors and no sideways scrolling.
  - Idle page activity is still 1 change.
  - Both plain-language test sets (54 and 45 questions) still pass in full, with a median under 0.2 s.

## Files
- **Changed:** `assets/tegh-portal-v5990.js`, `assets/tegh-r120.css` (section 22)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R124-CHANGES.md`
