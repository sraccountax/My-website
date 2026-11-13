# R182 plan — invoice corrections, mode switch review, new company page, banking entries and statement screens

R182 combines six changes. Each is described with what was found in the current code or screens (R181, examined on
the gate host) and what will change. Section A is the invoice-editing plan already reviewed, with your two answers.

| # | Change | Switch |
|---|---|---|
| A | Edit issued customer invoices (reverse and re-post, history, customer credit) | Site & Release Settings › **Edit issued invoices** (off by default) |
| B | Switching Guided ↔ Full Accounting goes to that mode's home and shows a review | none (always on) |
| C | New company page redesigned | none |
| D | Add bank transactions by hand (Match and Post, Bank Transaction Report), optionally every month | none |
| E | Edit descriptions in Match and Post and in the statement import review | none |
| F | PDF statement converter and import preview: one scroll area, next step in view | none |

B to F change screens only, plus one new server route for D. They do not change how anything is posted.

---

## A. Edit issued customer invoices

The approved plan is in the previous version of this file (git history). Decisions:

- **Customer credit** when a paid invoice's new total is lower (your choice).
- **Entry dates like QuickBooks:** the reversal goes on the original date and the corrected entry on the invoice date.
  This is allowed only while both periods are unlocked.
- **Cash-basis companies:** unpaid invoices only in R182. Paid ones keep using credit/debit notes.

In short:
- An **Edit invoice** button opens the editor with all fields. A preview shows the old and new totals, the entries
  and any credit. A reason is required. The invoice keeps its number and gets a "Corrected" label and a history.
- In the books, the original entry is reversed and the corrected one is posted. The payment is never changed.
- A lower total on a paid invoice leaves the difference as an unapplied **customer credit**, usable with the existing
  Apply Payment and Refund screens.
- Refused, each with a reason, when:
  - a period is locked;
  - a note is linked to the invoice;
  - the currency, rate or customer changes on a paid invoice;
  - the company is on cash basis and the invoice is paid;
  - the invoice is an opening invoice, void, or being sent.
- Permissions: `invoices.write` and `invoices.reverse`.

## B. Switching between Guided and Full Accounting

**Found:** `switchBookkeepingMode` changes the menus and shows a toast, but leaves you on the screen you were on. A
Full Accounting screen stays open after switching to Guided, and the other way round. Guided shows a "Welcome back"
toast only.

**Change:**

1. After switching, Tegh opens that mode's home: **Guided Home** for Guided, the **Dashboard** for Full Accounting.
   If the current form has unsaved changes, it asks first (the existing `canLeaveClientScope` check).
2. A **mode review** panel at the top of that home lists what is done and what is left, read from the company's
   actual records (no new data is stored). It can be dismissed.

   **Guided:**
   - next month to upload or post (from `guided/resume`, which already counts Full Accounting work);
   - transactions waiting for review;
   - months reconciled;
   - opening balances;
   - work done in Full Accounting since the last visit.

   **Full Accounting:**
   - unposted bank transactions and possible duplicates;
   - last reconciliation and difference;
   - AR and AP control vs subledger differences;
   - overdue invoices and bills due soon;
   - GST/HST payable;
   - opening balances;
   - ledger debits = credits;
   - work started in Guided (statement months uploaded but not posted).

   Each line has a status (done / needs attention / not started), a one-line note in plain words, and a button to
   the right screen.

## C. New company page (Settings › Add Company or Client File)

**Found:**
1. An 8-step tutorial opens over the form every time.
2. 21 fields sit in one undivided three-column grid. Required and optional fields are mixed (Short Name, Reporting
   Framework and Payroll GL Posting sit beside Company Name).
3. Time Zone, Short Name and Base Currency are placed inside the address block.
4. The time zone defaults to **UTC** for an Ontario company.
5. Two notes contradict each other: "Onboarding follows… you set up this company's chart" and "The Default chart is
   ready immediately".
6. A long Ontario tax explanation is always open, pushing the button far down.
7. The test-company checkbox is squeezed into a narrow column.
8. Headings are in Title Case, and the button is named "Create Separate Company File".

**Change:** a redesign by a designer who keeps books. Every field name and `data-` hook stays the same, so the tax,
location, currency (R181), onboarding and test-company logic keep working.

- **Layout:** a two-column page. On the left, numbered sections:
  1. **Business**: name, legal name (filled from the name), business type.
  2. **Where it operates**: country, province/state, and time zone (filled in from the province; Ontario →
     Toronto).
  3. **Books**:
     - currency (the R181 list when available);
     - fiscal year-end and start of books, with a one-line explanation of how they relate;
     - accounting basis as two choice cards (Accrual / Cash), each with one plain sentence.
  4. **Sales tax**: registered yes/no, then the number. The province explanation becomes a closed "What applies in
     Ontario" panel.
  5. **Modules**: Accounting / Accounting and Payroll as choice cards; payroll posting only when payroll is chosen.
  6. **Address and contact** and **More options** (short name, reporting framework) as collapsed, optional panels.

  On the right, a sticky **summary card**: what will be created, and what happens next (onboarding: chart of
  accounts, tax codes, opening balances).
- **Actions:** a sticky footer with Cancel and **Create company**. The test-company option is a clearly separate
  owner-only panel.
- **Tutorial:** no longer opens automatically on this page. A "How company setup works" link opens it.
- **Wording:** sentence-case headings, one consistent explanation of onboarding, required fields marked once, and
  errors shown next to the field.
- **Phone width:** one column, with the summary moved below the form.

## D. Add bank transactions by hand

**Found:** no route creates a bank transaction except importing a statement. The edit route
(`bank-transactions/edit`) and an "Edit transaction" action already exist in the Bank Transaction Report, but not in
Match and Post. Duplicates are found only by the statement's exact line fingerprint.

**Change:**
- New route **`bank-transactions/add`**. It records an unposted bank transaction with:
  - bank or card account, date, description, reference;
  - money in or money out;
  - optionally **repeat monthly** for 1–24 months, on the same day of the month (the last day where a month is
    shorter).

  Each line is marked **Entered by hand** and goes through the same review, matching, posting and reconciliation as
  imported lines. It respects period locks and the R181 dated-rate rule for foreign-currency accounts.
- **Add transaction** button in **Match and Post** and in the **Bank Transaction Report**, opening the same form.
- **Later statement imports:** a statement line on the same account with the same amount within 5 days of a
  hand-entered line is flagged **"May duplicate a transaction entered by hand on <date>"**. It is left unselected
  until reviewed, so nothing is counted twice.
- Audit: `bank_transaction.added_manually`, with the repeat schedule.

## E. Edit descriptions

**Found:** Match and Post has no edit. The statement import review ("Bank Statement Import Preview") shows
descriptions as plain text, although the server already accepts edited preview rows (`operations/statement-preview`,
PUT).

**Change:**
- **Match and Post:** an **Edit** action on unposted, unmatched lines. Description, reference and remarks are edited
  in place. Date and amount stay in the existing full edit, which keeps its rules: posted and matched lines cannot
  change, and locked periods are refused.
- **Statement import review:** each description is editable in place before import. Changes are saved to the server
  preview, the row selection is kept, and duplicate checks run again.

## F. PDF statement converter and import preview

**Found** (walked through on the gate host with a synthetic PDF):
1. The converter's review window scrolls inside itself (874 of up to 2,760 pixels visible) while the page behind can
   scroll too. The "Bank Statement Import Preview" also has its own scroll **plus the page scrollbar visible behind
   it**: two scroll areas.
2. You must scroll through the whole list before the "I checked the rows" box can be ticked.
3. After the converter closes, its result ("Conversion review complete…" with **Use in Upload Statement**) is added at
   the **bottom of the page, below the fold**. The empty spreadsheet-import card stays on top, so you have to scroll
   down even though the screen has room.

**Change:**
- **One scroll area per window:** the page behind is locked while the converter or preview is open. Each window fills
  the screen height, with a fixed header and footer, and only the transaction list scrolls.
- **Result in view:** after conversion, the result card moves to the top of the Upload Statement page and is scrolled
  into view and focused. The spreadsheet-import card collapses to a single line ("Import a CSV or Excel file
  instead") while a converted statement is waiting.
- **Upload Statement layout:** at desktop width, spreadsheet import and the PDF converter sit side by side, so both are
  visible without scrolling.
- The "I checked the rows" box stays required. The footer always shows it with the count of selected rows, and
  **Jump to end** reaches it in one click.

---

## Testing

1. **Canadian before-and-after comparison** (the R181 method: 609 captured reports and screens). With the switches
   off, figures and reports must be identical. The comparison covers data and the 17 captured screens. The new
   company page, mode review and banking buttons are new screens, checked by the suite below.
2. **New suite `56-r182`:**
   - A: the invoice-correction cases from the approved plan;
   - B: switching each way lands on the right home with a review whose lines match the records;
   - C: the new company page creates Canadian, US and (switch on) Indian companies as before. The time zone default
     is checked. No page errors at 1440, 768 and 390 wide;
   - D: adding one transaction and a 3-month series; a locked period is refused; an imported statement flags the
     manual line as a possible duplicate; posting and reconciliation work;
   - E: description edits in Match and Post and in the import review, then the import uses the edited text;
   - F: one scroll area while each window is open; the result card is in view after conversion.
3. **Full gate** on the exact ZIP.

## Order of work

1. Banking: D (route and form), E, F.
2. B (mode switch and review).
3. C (company page).
4. A (invoice corrections).
5. Tests, comparison, full gate, results.
