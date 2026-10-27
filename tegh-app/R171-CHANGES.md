# R171 — Company feed (beta)

R171 = R170 plus a **company feed**: a short stream of what is happening in the selected company, like a social-media
feed, docked on the Dashboard.

## Where it is

The feed is on the Dashboard and on Guided Home only. It does not cover the page's buttons or fields, except when you open it
yourself on a narrower screen.

- **Wide screens:** the open feed sits at the bottom right in its own strip, and the Dashboard narrows to make room.
  This applies where the page keeps at least 1000 pixels beside it, roughly screens 1620 pixels wide or more with the
  sidebar.
  - **Minimise** (–) closes it to a **Company feed** button in the page heading; the button shows how many new posts
    are waiting.
  - Tegh remembers on this device whether you keep it open or minimised.
- **Narrower screens:** the feed starts as the Company feed button in the page heading. Opened from there, it appears
  over the page until you minimise it or press Escape.
- **Phone:** the same button in the page heading opens the feed full screen.

## What a post looks like

- *"Editor added a new supplier, Lakeside Flour Co."*
- *"You issued invoice INV-1001 to Harbour Bakery · $1,130.00"*

Each post shows who did it, what was done, the amount where there is one, the area and the time. Posts are grouped
by day, and repeated actions by one person within 15 minutes become one post, for example "posted 12 bank
transactions", with **Show all**.

Choosing a post opens the record it names: the invoice, vendor invoice, note, payment list, bank transaction, journal
list, pay-run history or Invoice Intelligence review.

What appears:

| Area | Posts |
|---|---|
| Sales | invoices issued, drafted or voided; customer payments; new customers; credit holds |
| Purchases | vendor invoices entered, drafted (marked when read by Invoice Intelligence), sent for approval or approved; supplier payments; expenses; new suppliers; uploads for Invoice Intelligence and Document Intake; duplicate overrides |
| Notes | credit and debit notes drafted, posted or voided |
| Banking | bank transactions posted, statements imported, reconciliations, new bank accounts, voided transactions, transfers |
| Ledger | journals, opening balances, closing and reopening periods, new accounts, tax codes and budgets, depreciation |
| Payroll | pay runs finalized and payroll posted (no amounts) |
| Company | company created or updated, set-up finished, backups downloaded, team-member changes |

Filters: All, Sales, Purchases, Banking, Ledger, Notes, Payroll and Company, plus **Only other people**.

## Live updates

- New activity is fetched every 45 seconds while the window is on screen and the tab is visible. Refresh (↻) checks
  straight away.
- Posts by other people since you last looked are marked **New**. They stay marked while the window is open.
- Screen readers hear "n new posts in the company feed".

## Privacy and security

- The feed is built from the audit trail that Tegh already keeps. **Nothing new is recorded**, and reading the feed
  changes nothing.
- Only listed business events appear. Screen-layout preferences, downloads, sign-ins and other technical entries are
  never shown.
- People are shown by display name, or by the part of their address before "@". **Email addresses, IP addresses and
  browser fingerprints are never sent to the browser.**
- Who can read it:
  - anyone who can read the company's audit history (all company roles);
  - **payroll** posts only for people who can see payroll; they carry no amounts;
  - **team-member** posts name the member only for people who can see the team list; others see "a team member".
- Every request is checked against the company; another company's feed cannot be read.
- Names, memos and bank descriptions are shown as text. Markup inside them is displayed, never run.

## Limits

- The feed covers what the audit trail records: listed actions from the day each feature started writing audit
  entries. Older activity imported from other systems does not appear.
- The feed shows the **current company**. With several companies selected, it shows the one named in the window.
- There are no comments or reactions; the feed is read-only.

## Files

New:
- `api/activity_feed_r171.php` (GET `activity-feed`)
- `assets/tegh-feed-r171.js`, loaded on demand
- `assets/tegh-feed-r171.css`

Changed:
- `api/index.php`: route.
- `assets/tegh-portal-v5990.js`: starts the feed on the Dashboard and Guided Home, and opens records.
- `assets/tegh-gate-v5990.js`: on-demand module.
- `app.html`, `assets/tegh-preflight-v5990.js`: cache token `5990-r171-feed`.

No database change.
