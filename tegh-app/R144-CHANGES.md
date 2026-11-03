# R144 changes over R143: Tegh Intelligence (built into Tegh, no external AI)

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r144-tegh` (client view page unchanged: `5990-r135`).
Automatic migration: table `company_insight_dismissals`, created on the first "Not a problem" (CREATE privilege).
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## What it is
Tegh Intelligence is worked out by Tegh itself, from the company's own posted books, using rules and simple statistics:
- No outside AI service is called.
- No data leaves the server. `api/insights_r144.php` opens no network connections; a test checks this.
- It changes no accounting records. Every suggestion says why it was made, and nothing is posted until a person presses the normal Post or Save button.
- The only thing it stores is a "Not a problem" mark on a finding, which is recorded in the audit trail.

## Features
- **Tegh brief** (dashboard, under the summary sentence). Plain sentences, each opening the right screen:
  - cash in the bank and its change over 30 days;
  - overdue customer invoices and the oldest one;
  - vendor invoices past due or due in the next 7 days;
  - GST/HST owing or refundable;
  - bank lines waiting in Match and Post;
  - profit so far this year against the same point last year;
  - how many things are worth a look.
- **Tegh Intelligence page** (Reports › Management › Tegh Intelligence, "See all insights" on the dashboard, or ask Tegh Assist "anything unusual?"):
  - **Worth a look** (anomaly watch):
    - possible duplicate vendor invoices (same vendor and amount within 14 days, or the same number);
    - possible duplicate customer invoices (same customer, amount and date);
    - a vendor invoice at least 3× that vendor's usual amount (median of their last invoices, at least 3 needed);
    - an invoice whose tax code is for a different region from the customer's;
    - balances left in 6999 Unassigned Expense or 9999 Opening Balance Control;
    - bank lines waiting more than 30 days;
    - a bank account overdrawn in the books;
    - verified pay runs not posted.

    Each finding has **Open** and **Not a problem** buttons, and dismissals can be undone.
  - **Who to chase first:** overdue customers ranked by amount owed, days late and their payment habit (how many of their recent invoices were paid late). It links to the existing Collection Drafts.
  - **Cash runway:** cash in the bank, the average monthly change over the last active months, and months of runway at that rate.
    - A **what if** box shows the runway if a monthly cost is added.
    - A six-month chart has a table view.
- **Bank suggestions with confidence** (Match and Post). Selecting a bank line shows, for example, "Suggested: 6300 · Telephone and Internet · ON — 67% sure. You posted 'Rogers Wireless Pad' to 6300 Telephone and Internet with the ON tax code 2 times."
  - The suggestion comes from this company's own posting history (same merchant and direction) and from existing learned rules.
  - **Use suggestion** fills the account and tax code. The person still reviews the preview and presses Post Transaction.
  - Lines with no history get no suggestion; Tegh doesn't guess.
- **Ask your books** (Tegh Assist). Answered from the posted ledger with a sentence, the figures and the period used:
  - "how much did I spend on travel last quarter"
  - "what were my sales this year (vs last year)"
  - "biggest expenses this month"
  - "top customers this year"
  - "how much did we pay Rogers in September"

  Other requests route exactly as before.

## Files
- **New:** `api/insights_r144.php`.
- **Changed:** `api/index.php` (route `insights`), `assets/tegh-portal-v5990.js`, `assets/tegh-r120.css`, cache token in `app.html`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js`.
- **Docs:** README.txt, DEPLOYMENT-NOTES.txt, R144-CHANGES.md.
- **Manifests:** FILE-MANIFEST.sha256 and the release manifests.

## Limitations
- Findings and suggestions are rules and statistics, not judgement. They can miss things and can raise false alarms; "Not a problem" hides a finding.
- Bank suggestions need at least one earlier posting of a similar line.
- Questions answered from the books cover spending (by expense account or vendor), sales, biggest expenses and top customers, for a named period. Other questions route to the usual Assist commands.
- Cash runway uses past bank movement, not a forecast. Use Cash Forecast for a forward view.
- Dismissals are not included in backups.
