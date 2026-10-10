# R163 changes over R162: Guided bank statements, automatic reconciliation and Connect with an accountant

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r163-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: two tables are added on first use, `guided_statement_months` and `accountant_requests`. No manual migration.

## Guided mode starts with the bank statements
Guided Home opens with a **Start here · Bank statements** card. It shows how many months of the fiscal year are uploaded and reconciled, and opens **Bank Statements** (step 1 of 3).

### One statement per month
- **Fiscal year and account:** choose a fiscal year (from the books start date to the current year) and a bank or card account. Tegh lists the 12 months of that year, for example January to December, or April to March for a March 31 year end.
- **Month status:** each month shows *Not uploaded*, *Uploaded · N to post*, *Uploaded · posted* or *Uploaded · reconciled*.
- **One upload at a time:** a month can hold one statement.
- **Removing a statement:** an uploaded statement can be removed while none of its lines has been posted. Its lines are deleted and the month is free again.
- **Which month a statement belongs to:** the month the statement was **issued**. A statement issued in February usually holds January transactions and runs to early February. Tegh accepts a statement for the month of its last transaction, or the month after it.
- **Wrong month:** a statement uploaded against the wrong month is stopped before anything is imported. The message names the right month and offers **Upload it to *month* instead**.
- **Lines outside the fiscal year:** when a statement starts or ends outside the fiscal year, Tegh shows a note before the import, for example "1 transaction is dated before the fiscal year starts (January 1, 2026)". Those lines are imported with the statement. The note stays on the month row and on the review page.
- **Duplicate check:** each upload goes through the normal statement verification, including the duplicate check.

### PDF statements
The bank statement converter sits beside the month list (below it on a phone). A converted PDF is handed straight to the month being uploaded.

### Review and post (step 2 of 3)
After the import, the lines are grouped by a **keyword** (the first two meaningful words of the description, without numbers) and by direction (money in or out). For example, 500 "ROGERS WIRELESS 1234…" lines form one group.

- **Suggestions:** each group shows the account and tax Tegh suggests. These come from the company's own rules and history, or from the built-in keyword rules, and can be changed.
- **Summary:** the page shows how many lines were mapped automatically, how many need an account, and how many are posted.
- **Lines view:** **Lines** opens the lines of a group. A line can be given its own account and tax, and is then posted on its own.
- **Bulk selection:** **Select all groups with an account** ticks every group that is ready. One **Post N lines in M groups** posts them through the normal posting service (same tax split, period locks and audit trail), 100 lines per request, with a progress bar.
- **Refused lines:** a line the posting service refuses (a locked period, for example) is listed and left to post; the others are posted.
- **Deposit hint:** a GST/HST-registered company sees a reminder to choose the tax on deposit groups when the deposits include GST/HST.

### Automatic bank reconciliation
When every line of the statement is posted or excluded, Tegh completes the bank reconciliation for the statement period, from the first line (or the day after the last completed reconciliation) to the last line.

- **Normal service:** the reconciliation is saved by the normal reconciliation service. It completes only when the bank side (opening balance and imported lines) and the General Ledger agree to the cent; otherwise the reason is shown.
- **Posted lines are included directly:** each posted line already carries its own journal, so the reconciliation includes it without a separate match.
- **Result:** the month then shows *Uploaded · reconciled*, and the reconciliation appears in Bank Reconciliation history with its report.

### What next? (step 3 of 3)
- **Yes, record invoices or expenses:** asks what to record first (customer invoices, vendor invoices or expenses), switches to **Full Accounting** and opens that screen. The books stay as they are, and the person can come back to Guided from the profile menu.
- **No, show my financial statements:** opens **Your Financial Statements**, with the Trial Balance, Profit and Loss and Balance Sheet.

## Connect with an accountant
A new button in the top bar, right after Tegh Assist, opens **Connect with an accountant**.

### Review and quote
Tegh reviews the company's books and lists what it finds, split into "To finish" and "To check":
- bank lines not posted;
- postings to 6999 Unassigned Expense;
- a trial balance out of balance;
- months not reconciled;
- months with no bank activity;
- receivables or payables that differ from their control accounts;
- possible duplicate bank lines;
- draft documents;
- missing opening balances;
- GST/HST return preparation;
- a final review of the statements.

It shows **one total in CAD**, plus applicable GST/HST.

**The rate stays private:**
- The time for each finding and the hourly rate stay on the server. The page, the API response and the client's email draft carry only the findings and the total.
- The rate is `config('accountant.hourly_rate_cents')`, default 3000 ($30 CAD per hour). Time is rounded up to the quarter hour.

### Sending the request
The person can add a note and a phone number, and ticks a box to send the request and findings (the scope is confirmed before any work starts). On **Send request**:
- **Stored:** the request is saved (`accountant_requests`).
- **Emailed:** the request goes to `config('accountant.request_email')` (default **enquiry@sraccountax.ca**) through the host's mail settings. That email includes the time per finding and the rate.
- **Outlook draft:** Outlook on the web opens with a draft already filled in: to enquiry@sraccountax.ca, the subject with the request reference, and a body with the findings, the total and the note (no rate or hours). Links to the Outlook web and desktop app drafts are shown too, in case a pop-up blocker stopped the window.
- **Limit:** a company can send five requests an hour.
- **Who can send:** owners, admins, bookkeepers and editors.

## Other changes
- **Faster reconciliations (Full Accounting too):**
  - Before: completing a reconciliation took about a minute on a 5,000-line period. The query that finds the cleared lines joined every journal of the company.
  - Now: it checks each bank line directly and takes 0.1 s.
  - Same result: the old and new queries were compared on every bank account of the test host, including 5,000 lines cleared through match groups, and returned the same lines. (`api/accounting.php`)
- **Built-in keyword rules:**
  - Fixed: income rules (sales, deposits, payouts) now apply only to money in, and expense rules only to money out. Before, a refund from a payout service could be suggested as revenue on a payment.
  - New rules for common Canadian expenses: telephone and internet, postage, meals, travel and parking, fuel, insurance, bank charges and more software. (`api/imports.php`)
- **Reusable reconciliation save:** saving a reconciliation is now a service (`reconciliation_save_service`) used by both the Bank Reconciliation screen and Guided mode. The behaviour is unchanged.

## Tests (31-r163, 31 checks)
Every figure is worked out from the CSV text (13% HST included in the bank amount: net = round(gross × 100 / 113), tax = gross − net). None of Tegh's own calculations are used.

### Through the API
- **Fiscal years (R-01, R-02):** 12 months for a December year end. For a March 31 year end, the current year (April 2026 to March 2027) and the year before.
- **Choosing the month (R-03, R-04):**
  - A statement ending February 3 is refused for May (the message names February) and for January. February and March are accepted.
  - It has 1 line before the fiscal year and 0 after.
- **Recording and duplicates (R-05, R-06):** the statement is recorded as February (6 lines, Dec 30 to Feb 3). A second February statement, and the same import recorded twice, are refused.
- **Mapping (R-07 to R-09):**
  - Five keyword groups with the expected accounts and HST; 5 lines mapped and 1 not.
  - Reconciling before posting is refused.
  - The group's lines are listed.
- **Posting (R-10):** the groups are posted, with the deposit changed to HST included and the unknown line posted on its own to 6999. The General Ledger equals the hand figures: bank 304.00, HST paid 26.00, HST collected 65.00, revenue 500.00.
- **Reconciliation (R-11, R-12):** completed for Dec 30 to Feb 3 with difference 0 and 6 cleared lines; repeating it is harmless. The reconciliation report agrees.
- **Removing (R-13):** an unposted statement is removed (its lines deleted). A posted one is refused.
- **Company isolation (R-14):** another company gets 404 on the mapping, posting, reconciling and removal, and its attempt to record this company's import is refused.
- **5,000-line statement (R-15 to R-17):**
  - Grouped into 10 groups in 2.1 s.
  - Posted in 50 requests (slowest 3.7 s, about 3 minutes in all). The bank, HST and every expense account equal the hand sums.
  - Reconciled in one request in 1.4 s with 5,000 cleared lines.
- **Accountant (R-18 to R-20):**
  - The review carries no minutes, hours or rate.
  - The request is stored and emailed to enquiry@sraccountax.ca with the breakdown ($30.00 CAD/h; hours × rate = quote). The draft has no rate or hours.
  - The sixth request in an hour is refused.

### In the browser
- **Start (R-21, R-22):** the Home card comes first, and the accountant button sits right after Tegh Assist. The month page shows 12 months and the converter.
- **Uploading (R-23, R-24):**
  - The February statement uploaded against May is stopped. The page offers "Upload it to February 2026 instead", and the note says 1 transaction falls before the fiscal year.
  - The review page shows 6 lines in 5 groups.
- **Posting and reconciling (R-25, R-26):** choosing 6999 for the unknown group, selecting all and posting reconciles the month automatically. The month row and Home progress update.
- **Next step (R-27, R-28):** "No" opens the financial statements page. "Yes" switches to Full Accounting and opens Customer invoices.
- **Accountant (R-29):** the CAD quote is shown with no hours or rate on screen. Sending opens the Outlook draft to enquiry@sraccountax.ca (Outlook stubbed on the test host).
- **Phone width (R-30):** at 390 px, neither the page nor any table or card scrolls sideways.
- **Script errors (R-31):** none.

## Upgrading from R162
Back up first, then upload the whole package (cache token r163-tegh). The two tables are created on the first Guided statement or accountant request. To change the address or the rate, add the `accountant` section shown in `config.example.php` to `config.php`.
