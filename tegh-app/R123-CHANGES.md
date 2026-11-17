# R123 changes over R122: Tegh Assist

5.9.9 / Build 5990 / Schema 46. Cache token `r123-tegh`. No database migration.

## Audit findings

| Finding | Before | After |
|---|---|---|
| Time to understand one typed request | about 15 seconds (ranking step alone: 15.3 s) | about 0.2 seconds end to end (median 170–200 ms) |
| Everyday questions understood (99-question test) | 39 of 99 | 99 of 99 |
| Answered without an extra "which did you mean?" step | 18 of 99 | 99 of 99 |
| Formal screen and report names still found (170 names) | not measured | 169 of 170 |
| Tegh Assist on phones | not reachable (button hidden below 820 px) | icon in the top bar |

- **Why it was slow.** Every question re-ran spelling correction over the text of all 170 actions (about 15 seconds). Tegh also rebuilt the action list and re-checked feature access about 170 times per question (about 0.4 seconds).
- **Why everyday wording failed.**
  - Ordinary words were "corrected" into accounting terms: "owes" became "owed", "hello" became "help", "stuff" became "staff".
  - Nothing mapped phrases such as "who owes me money" or "am I making money" to the right report.
  - A question with no dates stopped to ask for a period.
  - Screens were blocked by questions that did not apply to them: "customer list" asked "which customer?", and "close the month" asked for reporting dates.

## What changed

### Faster
- Action text is prepared once and reused. Word comparisons are remembered within a request.
- The action registry is built once per request, and each feature-access key is checked once instead of once per action.

### Understands everyday language
- **Everyday phrases.** About 70 phrase patterns map common wording to the right Tegh action. Some examples:

  | You type | Tegh opens |
  |---|---|
  | "who owes me money" | Receivable Ageing |
  | "who hasn't paid me yet" | Overdue customer invoices |
  | "how much do I owe" | Payables Ageing |
  | "what bills are due this week" | Upcoming payments |
  | "am I making money", "how did the business do last year" | Profit & Loss |
  | "what do I own and owe" | Balance Sheet |
  | "will I run out of cash" | Cash Forecast |
  | "bill a customer" | New customer invoice |
  | "I got a bill from my supplier" | New vendor invoice |
  | "a customer paid me" | Customer Payments |
  | "pay the electricity bill" | Vendor Payments |
  | "how much GST do I owe" | Tax Summary |
  | "run payroll" | Pay Run Register |
  | "invite my accountant" | Users |

- **Shorthand, contractions and typos.**
  - Contractions and missing apostrophes: "hasnt", "whos", "dont".
  - Shorthand: "P&L", "p and l", "profit n loss", "A/R", "txns", "e-transfer", "bank rec".
  - Common misspellings: "trial balence", "income statment", "invoce", "forcast".
- **Protected words.** About 300 common English words are never spell-"corrected".
- **Everyday dates.**
  - Relative periods: "this week", "last week", "next week", "next month", "next 3 months", "last 6 weeks".
  - Named periods: "this year", "last year", "Q2", "in March", "2025".
- **Sensible defaults.** A report with no dates runs on a labelled default, which stays editable:
  - year to date for Profit & Loss, Trial Balance and Cash Flow;
  - today for ageing and the Balance Sheet;
  - the next 30 days for bills due and expected collections;
  - the next 3 months for the cash forecast.
- **No irrelevant questions.** Opening a screen never asks for a customer, a period or a comparison that the screen cannot use.
- **Names are kept.** "create customer Acme Rentals" still prepares the customer with the name filled in.

### Answers everyday questions
The built-in guide now answers common small-business questions in plain language, with a link to the right screen:
- when to register for GST/HST;
- deducting a vehicle, a home office or meals;
- paying yourself;
- how long to keep records;
- input tax credits;
- when payroll remittances are due;
- invoice versus bill;
- asset versus expense.

Each answer says it is general guidance and to confirm with CRA or an accountant. When the guide has no answer, it now says so, instead of returning an unrelated month-end summary.

### Quicker to use
- **A confident answer acts at once:**
  - a report runs in the panel;
  - a screen opens directly.

  Anything that records, posts or pays still stops at its normal review and confirmation.
- **Suggestions under the question box:** "Who owes me money?", "Am I making money this year?", "What bills are due soon?", "How much GST do I owe?", "Upload my bank statement" and "Pay a supplier".
- **Unclear requests:** instead of a technical message, Tegh says "I didn’t quite catch that" and shows examples.
- **Phones:** Tegh Assist is back in the phone top bar.

## Safety
- Nothing new is executed automatically.
- Phrase rules only raise an action's ranking. Permissions, feature access, parameter checks, previews and confirmations are unchanged.
- Nonsense input never opens a screen: a screen or report opens automatically only for a strong match (confidence 0.85 or higher).

## Verified
Checked on local PHP 8.4 and MariaDB, over HTTP and with Playwright (Chromium).

- **Plain-language tests:** 54 everyday questions and a separate held-out set of 45 different phrasings, including typos.
  - All 99 go to the right action.
  - None needs a "which did you mean?" step.
  - Median time is under 0.2 s.
- **Formal names:** 169 of 170 action names still find their own action. "Open Payroll verification" goes to the Payroll workspace.
- **Ask Tegh endpoint:** requests return in 20–280 ms.
- **In the panel:**
  - "Who owes me money?" shows Receivable Ageing.
  - "am I making money this year" shows Profit & Loss year to date.
  - "pay my supplier" opens Vendor Payments.
  - "upload my bank statement" opens the statement upload.
  - "run payroll" opens the Pay Run Register.
  - "can I deduct my car" gives the plain answer.
  - "hello" gets the friendly not-understood message.
- **Phone:** the Assist icon fits the top bar at 320, 360, 390 and 768 px, with no sideways scrolling.
- **Regression:**
  - All 46 menu screens load on desktop (1440×900) and phone (390×844) with no errors and no sideways scrolling.
  - Idle page activity is still 1 change.
  - PHP and JavaScript syntax checks pass for every file.

## Files
- **New:** `api/assist_language_r123.php`, `R123-CHANGES.md`
- **Changed:**
  - `api/ai_router.php`
  - `api/ai_actions_v5600.php`
  - `api/command_centre_v5600.php`
  - `api/ai_agent.php`
  - `assets/tegh-portal-v5990.js`
  - `assets/tegh-r120.css`
  - `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js` and `app.html` (cache token only)
