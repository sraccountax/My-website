# R162 changes over R161: Guided mode tested end to end

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r162-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: no change.

## How it was tested
A new gate suite, **30-guided**, books a fresh company the way a Guided user would. The company is in Ontario, registered for HST, with eight September statement lines imported.

- Every step goes through the Guided screens: Home, Match and Post, Bank Transactions, the month-end checklist and the owner reports.
- Every journal is compared with amounts worked out by hand (13% HST included in the bank amount, for example 113.00 = 100.00 + 13.00). None of Tegh's own calculations are used for the expected figures.

What the suite does:
- **Post lines one at a time:** office supplies and a customer deposit with HST, and a bank fee with no tax.
- **Post in bulk:** three phone bills posted together.
- **Exclude and restore:** an owner's personal purchase is excluded, restored, then posted to Owner Draws.
- **Void and re-post:** the bank fee is voided and posted again.
- **Phone width:** a software subscription is posted at 390 px.
- **Match:** all eight posted lines are matched in one step.
- **Month end:** the month-end checklist is run.
- **Reports:** Home, Profit and Loss and the General Ledger are checked against the hand-worked totals: bank 183.60, HST owed 28.60, profit 195.00.

## Defects found and fixed
**1. The Banking menu stopped responding after posting (Guided and Full Accounting).**
- **What happened:** after a bank line was posted, the Banking badge changed (8 → 2), and from then on clicking Banking in the side menu did nothing until the page was reloaded.
- **Cause:** the side menu rows belong to the core app. When the core app redraws a row, it replaces Tegh's click handler with an empty one.
- **Fix:** Tegh keeps each row's handler, and its menu click watcher runs that handler if it has been replaced. (`assets/tegh-portal-v5990.js`)

**2. A voided bank posting could never be posted again.**
- **What happened:** Void (from Bank Transactions) takes a posted bank line out of the books and marks it excluded. After Restore, the line was back in Match and Post, but posting it failed with "This statement line is already recorded or matched in the books". If that check was passed, the save failed with a server error.
- **Cause:** the restored line still pointed at its voided journal, and the voided journal still held the line's single journal link in the database.
- **Fix:** restoring a voided line now moves its voided journal aside. The journal keeps its lines, void date, reason and audit history, and is listed in the void register as a voided bank posting (`bank_transaction_voided`). The line can then be posted again. The posting check now refuses a line only when it is linked to a journal that is still in the books. Lines left in this state by an earlier release are released the same way when they are next posted. (`api/accounting.php`)

**3. Restoring a voided bank line could leave it marked Excluded, or count it twice.**
- **What happened:** "Restore" in the void register brought the bank line's journal back into the books, but the line stayed Excluded. After a re-post, restoring the old entry (as a bank line or as a journal) would have put the same bank line in the books twice.
- **Fix:**
  - Restoring a voided bank line brings back only that line's own posting, and marks the line Posted again.
  - Once the line has gone back to review, both routes are refused with "Post it again from Match and Post instead".
  - The void register no longer offers Restore on such an entry. (`api/voids.php`, `assets/tegh-portal-v5990.js`)

**4. The Guided month-end checklist had no button.**
- **What happened:** the Guided "Finish *month* Bookkeeping" checklist could not be reached from anywhere in Guided mode. The Home "Month-end checklist" link opened the Full Accounting month-end close.
- **Fix:** Guided Home's shortcuts now include **Month-End Checklist**. (`assets/tegh-portal-v5990.js`)

**5. Opening a row's Actions menu ticked the row.**
- **What happened:** in tables with row checkboxes (for example Bank Transactions), clicking a row's **Actions** menu also ticked or unticked that row and showed the "1 row selected" bar. A bulk action could then include a row the person did not choose.
- **Fix:** the Actions menu and its items no longer change the row selection. (`assets/tegh-modern-v5700.js`)

**6. Layout fixes in Match and Post.**
- **Footer buttons:** when a long message showed above the buttons, the buttons were squeezed until their labels broke mid-word ("Exclud e from Books"). The message now takes its own line.
- **Phone status:** at phone width, the status pill broke mid-word ("Poste d"). It now stays on one line. (`assets/tegh-r157.css`)

## Gaps found in the earlier tests
- **Guided bookkeeping actions had not been tested.** R161 opened every Guided screen with large companies, but did not post, exclude, void or match anything through them.
- **The defects above appear only after a change.** Each showed up only after a posting, a void or a badge change. The 30-guided suite now does all of these in order on every gate run.

## Gate tests (30-guided)
- **G-00:** set-up.
- **G-01…02:** Home count, badge and statement balance.
- **G-03…06:** single and bulk postings, each checked against hand-worked journals and the posting preview.
- **G-07:** counts, and the Banking menu after its badge changes.
- **G-08…10:** exclude, restore without ticking the row, and post.
- **G-11 / G-11b:** void, restore and re-post. Bringing back the old entry is refused through both routes.
- **G-12:** phone width: no sideways scroll, status pills on one line, posting.
- **G-13…14:** Home after all lines are done, and matching.
- **G-15:** General Ledger equals the hand-worked balances.
- **G-16:** month-end checklist from Guided Home.
- **G-17:** Home and Profit and Loss figures.
- **G-18:** no script errors.

## Upgrading from R161
Back up first, then upload the whole package (cache token r162-tegh). No database change.
