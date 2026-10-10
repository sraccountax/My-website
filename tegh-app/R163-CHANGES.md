# R163 changes over R162: Guided bank statements, reconciliation against the statement balance, continuing from Full Accounting, and Connect with an accountant

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r163-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: three tables are added on first use, `guided_statement_months`, `guided_statement_batches` and `accountant_requests`. No manual migration.

## Guided mode starts with the bank statements
Guided Home opens with a **Start here · Bank statements** card. It shows how many months of the fiscal year are uploaded and reconciled, and opens **Bank Statements** (step 1 of 3). Once work has started, the card reads **Continue where you left off** and its button names the next step (see below).

### One statement per month
- **Fiscal year and account:** choose a fiscal year (from the books start date to the current year) and a bank or card account. Tegh lists the 12 months of that year, for example January to December, or April to March for a March 31 year end.
- **Month status:** each month shows one of:
  - *Not uploaded*;
  - *Included in the X statement*: the month's usual period is inside another statement (it can still be uploaded);
  - *Uploaded · N to post*;
  - *Posted · balance to check*;
  - *Uploaded · reconciled*, or *Reconciled in Bank Reconciliation*.
- **One upload at a time:** a month can hold one statement.
- **Removing a statement:** an uploaded statement can be removed while none of its lines has been posted or matched. Its lines are deleted and the month is free again.
- **Which month a statement belongs to:** the month the statement was **issued**. A statement issued in February usually holds January transactions and runs to early February. Tegh accepts a statement for the month of its last transaction, or the month after it.
- **Wrong month:** a statement uploaded against the wrong month is stopped before anything is imported. The message names the right month and offers **Upload it to *month* instead**.
- **Lines outside the fiscal year:** when a statement starts or ends outside the fiscal year, Tegh asks before the import, with the count and the net amount, for example "1 transaction is dated before your fiscal year starts (January 1, 2026), net −$56.50".
  - Those lines are imported with the statement and recorded on their own dates, in the other fiscal year. The question says to check with the accountant first if that year is already filed.
  - The note stays on the month row and on the review page.
- **Period already in the books:** when the account already has lines in the statement's period (imported before, here or in Banking), Tegh says so before the import. Lines that match exactly are recognised as duplicates and not imported twice.
- **Duplicate check:** each upload goes through the normal statement verification, including the duplicate check.

### PDF statements
The bank statement converter sits beside the month list (below it on a phone). A converted PDF is handed straight to the month being uploaded.

### Review and post (step 2 of 3)
After the import, the lines are grouped by a **keyword** (the first two meaningful words of the description, without numbers) and by direction (money in or out). For example, 500 "ROGERS WIRELESS 1234…" lines form one group.

- **Suggestions:** each group shows the account and tax Tegh suggests. These come from the company's own rules and history, or from the built-in keyword rules, and can be changed.
- **Summary:** the page shows how many lines were mapped automatically, how many need an account, and how many are posted.
- **Lines view:** **Lines** opens the lines of a group. A line can be given its own account and tax, and is then posted on its own.
- **Bulk selection:** **Select all groups with an account** ticks every group that is ready. A group whose lines had different suggestions is not ticked automatically; its note asks the person to check it with **Lines**. One **Post N lines in M groups** posts them through the normal posting service (same tax split, period locks and audit trail), 100 lines per request, with a progress bar.
- **Refused lines:** a line the posting service refuses (a locked period, for example) is listed and left to post; the others are posted.
- **Deposits and GST/HST:**
  - For a company registered for GST/HST, a group of deposits to an income account has no tax suggestion. The tax shows **Choose…** and the group cannot be posted until the person says whether the deposits include GST/HST; the server refuses it too.
  - Before, deposits defaulted to *No tax*, which overstates revenue and understates the HST collected when they do include it.
  - A company not registered for GST/HST or PST never gets a tax suggestion.

### Bank reconciliation against the statement's closing balance
When every line of the statement is posted, matched or excluded, Tegh checks the statement against the bank:
- **What is compared:** Tegh's balance for the account on the statement's last date (the opening balance plus every imported line not excluded) is compared with the **closing balance printed on the statement**.
- **Where the closing balance comes from:** the statement file when it has a balance column (nothing to type), otherwise one box: *Closing balance on the statement*. For a credit card the box asks for the balance owed.
- **When they agree to the cent:** Tegh completes the bank reconciliation for the statement period, from the first line (or the day after the last completed reconciliation) to the last line. It is saved by the normal reconciliation service, which also requires the bank and the books to agree. Each posted line carries its own journal, so no separate match is needed. The month shows *Uploaded · reconciled*, and the reconciliation appears in Bank Reconciliation history with its report.
- **When they differ:** Tegh shows both balances and the likely causes:
  - the account has no opening balance in Tegh;
  - lines of the statement are excluded or marked as duplicates (with their count and total);
  - a line is missing from the file, or the balance was typed differently.
- **Not now:** the month stays *Posted · balance to check*. It is not shown as reconciled.

Why: before this change, the reconciliation compared the imported lines with the postings made from those same lines, so it always agreed. A line lost in conversion, an excluded line that was real, or a wrong opening balance went unnoticed. The closing balance from the bank is the independent check.

## Switching between Full Accounting and Guided
Guided keeps its place, and it also takes in what was done in Full Accounting, so the person continues from where the books actually are:
- **Statements imported in Banking:** these join the month list the next time Guided looks at the account. Each goes to the month of its last transaction, or the month after if that month already has a statement; otherwise it joins the first. A month can therefore hold several imports. The row says *imported in Banking*.
- **Months inside another statement:** a month whose usual period (the month before, give or take a week) is inside another statement shows *Included in the X statement*. For example, an import from January 5 to March 28 makes the March statement and covers February and April.
- **Posted and matched lines:**
  - Lines posted in Full Accounting count as posted.
  - Lines matched to a book entry (an invoice payment, for example) count as done and are never offered for posting again.
- **Reconciliations:** a statement whose posted lines are all in a reconciliation completed in Bank Reconciliation shows *Reconciled in Bank Reconciliation*.
- **Where to continue:** Guided Home's button and a banner on the month list say what comes next:
  - post the first statement with lines left;
  - then check the balance of the first one not yet checked;
  - then upload the next month without a statement, from the books start to this month (a statement issued in January covers December, so for books starting January 1 the first is February);
  - otherwise the next step.
- **Welcome back:** switching from Full Accounting to Guided shows a *Welcome back to Guided* notice with the same next step.
- **Lines outside any statement:** lines added to the account outside a statement (by hand, for example) are counted, with a link to Match and Post.
- **An upload in progress:** if the month list takes in an upload before Guided has recorded it, it is still recorded against the month the person chose.

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
- months with no bank transactions;
- excluded bank lines to confirm;
- uploaded statements not yet checked against their closing balance;
- receivables or payables that differ from their control accounts;
- possible duplicate bank lines;
- draft documents;
- missing opening balances;
- GST/HST return preparation;
- a final review of the statements.

It shows **one total in CAD**: an estimate for the bookkeeping work listed, plus applicable GST/HST. The page says it does not include tax returns (GST/HST, T2 or personal) or payroll filings, and that a Tegh accountant confirms the scope and the price before starting.

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
- **Fix (Full Accounting too): a matched line blocked every reconciliation covering it.**
  - What happened: a bank line matched in Bank Reconciliation to an existing book entry (an invoice payment, for example) stays unposted, because the entry is already in the books. The reconciliation still counted it as "not posted" and ignored the entry it was matched to. Every reconciliation covering such a line reported an unexplained difference, 1,130.00 in the test, and could not be completed.
  - Fix: a matched line is now in the books through its matched entry and is recorded as cleared. Found by the new Full-Accounting-then-Guided test. (`api/reconciliation_position_r122.php`, `api/accounting.php`)
- **Faster reconciliations (Full Accounting too):**
  - Before: completing a reconciliation took about a minute on a 5,000-line period. The query that finds the cleared lines joined every journal of the company.
  - Now: it checks each bank line directly and takes 0.1 s.
  - Same result: the old and new queries were compared on every bank account of the test host, including 5,000 lines cleared through match groups, and returned the same lines. (`api/accounting.php`)
- **Built-in keyword rules:**
  - Fixed: income rules (sales, deposits, payouts) now apply only to money in, and expense rules only to money out. Before, a refund from a payout service could be suggested as revenue on a payment.
  - New rules for common Canadian expenses: telephone and internet, postage, meals, travel and parking, fuel, insurance, bank charges and more software.
  - From the accounting review:
    - Telephone rules match whole words, so "CAMPBELL" or "BELLEVILLE" no longer suggest Bell.
    - LCBO is no longer suggested as a meal.
    - Taxi, rideshare, rail and air fares suggest GST/HST included, like hotels and parking.
    - "Customer payment" deposits are no longer suggested as revenue: they usually settle an invoice, and revenue would be counted twice.
  - (`api/imports.php`)
- **Fiscal years ending in February:** a year end on the last day of February stays on the last day every year. The year ending February 28, 2025 starts March 1, 2024, and the one ending February 29, 2024 starts March 1, 2023. Before, such a year could start on February 29 and show 13 months.
- **Reusable reconciliation save:** saving a reconciliation is now a service (`reconciliation_save_service`) used by both the Bank Reconciliation screen and Guided mode. The behaviour is unchanged.

## Accounting review
An independent senior-accountant review of R163 (read-only) found:
- **Fixed in this release:**
  - the reconciliation could not detect a missing or excluded line (now checked against the statement's closing balance);
  - deposits defaulted to *No tax*;
  - mixed-suggestion groups were posted on the majority suggestion;
  - the rule matches above;
  - February year ends;
  - tax suggested to non-registrants;
  - the quote's wording.
- **Confirmed correct:**
  - the faster cleared-lines query is equivalent to the old one;
  - the statement-month rule and the fiscal-year cut-off.
- **Left as limits:**
  - meals are suggested with no tax (conservative: the 50% input tax credit and the 50% income-tax limit are left to the accountant);
  - a statement with no transactions cannot be uploaded;
  - the estimate does not cover payroll, fixed assets, accruals or tax filings.

## Tests (31-r163, 46 checks)
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
- **Reconciliation (R-11, R-12):**
  - Without a closing balance the month waits.
  - With the statement's 304.00 (the hand sum) it completes for Dec 30 to Feb 3 with difference 0 and 6 cleared lines; repeating it is harmless.
  - The reconciliation report agrees.
- **Removing (R-13):** an unposted statement is removed (its lines deleted). A posted one is refused.
- **Company isolation (R-14):** another company gets 404 on the mapping, posting, reconciling and removal, and its attempt to record this company's import is refused.
- **5,000-line statement (R-15 to R-17):**
  - Grouped into 10 groups in 2.8 s.
  - Posted in 50 requests (slowest 3.8 s, about 3 minutes in all). The bank, HST and every expense account equal the hand sums.
  - Reconciled against the closing balance in one request in 1.8 s with 5,000 cleared lines.
- **Accountant (R-18 to R-20):**
  - The review carries no minutes, hours or rate.
  - The request is stored and emailed to enquiry@sraccountax.ca with the breakdown ($30.00 CAD/h; hours × rate = quote). The draft has no rate or hours.
  - The sixth request in an hour is refused.

### Full Accounting first, then Guided (R-32 to R-44)
- **Picking up Full Accounting work (R-32 to R-34):**
  - A January–March statement is imported in Banking. Two of its lines are posted there, and one deposit is matched to an invoice payment entry.
  - Guided shows it as the March statement: 3 to post, 2 posted, 1 matched.
  - February and April show as included in it.
  - Guided continues with "post the March statement".
- **Deposit tax (R-35):** the deposit group must have its tax chosen; posting without it is refused.
- **Closing balance (R-36, R-37):**
  - A closing balance 10.00 off is refused. Tegh's balance equals the hand sum 1,500.50, and the hint names the missing opening balance.
  - The right balance completes the reconciliation with 6 cleared lines, the matched one included.
- **Back to Full Accounting (R-38 to R-40):**
  - Guided continues with May.
  - An April–May statement imported, posted and reconciled in Full Accounting shows in Guided as *Reconciled in Bank Reconciliation*, and Guided continues with June.
  - The Full Accounting reconciliation report has no unexplained difference.
- **Other cases (R-41 to R-44):**
  - An upload taken in by the month list before it was recorded is still recorded as June.
  - February 28 and 29 year ends give 12 months.
  - A company not registered for GST/HST gets no tax suggestion.
  - A file with a balance column reconciles without typing (closing −81.50).

### In the browser
- **Start (R-21, R-22):** the Home card comes first, and the accountant button sits right after Tegh Assist. The month page shows 12 months and the converter.
- **Uploading (R-23, R-24):**
  - The February statement uploaded against May is stopped. The page offers "Upload it to February 2026 instead", and the note says 1 transaction falls before the fiscal year.
  - The review page shows 6 lines in 5 groups.
- **Posting and reconciling (R-25, R-26):**
  - The deposit group waits for its GST/HST choice.
  - After choosing it and 6999 for the unknown group, *Select all* and *Post* book all 6 lines.
  - A closing balance of 300.00 is refused with both balances; 304.00 reconciles. HST collected is 65.00.
  - The month row and Home progress update, and Home offers "Continue: upload the March 2026 statement".
- **Next step (R-27, R-28):** "No" opens the financial statements page. "Yes" switches to Full Accounting and opens Customer invoices.
- **Accountant (R-29):** the CAD quote is shown with no hours or rate on screen. Sending opens the Outlook draft to enquiry@sraccountax.ca (Outlook stubbed on the test host).
- **Phone width (R-30):** at 390 px, neither the page nor any table or card scrolls sideways.
- **Round trip (R-47, R-48):**
  - After work in Full Accounting, switching to Guided shows *Welcome back to Guided*.
  - Home's "Continue: post the March 2026 statement" opens that statement with the line posted in Full Accounting counted.
  - The month list shows the Banking import, February included in it, and the *Continue where you left off* banner.
- **Script errors (R-31):** none.

## Upgrading from R162
Back up first, then upload the whole package (cache token r163-tegh). The three tables are created on the first Guided statement or accountant request. To change the address or the rate, add the `accountant` section shown in `config.example.php` to `config.php`.
