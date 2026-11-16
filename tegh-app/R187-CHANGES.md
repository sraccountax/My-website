# R187: General Ledger detail, one Journal Entry, interbank transfers, reconciliation and downloads

R187 = R186 plus the changes below. There is no switch and no database change. Nothing already in the books is rewritten.

## 1. General Ledger lines name the invoice or note

Every General Ledger line of these entries now names the document and the party:
- customer invoice, vendor invoice, credit note, debit note and supplier notes;
- invoice correction;
- a receipt or payment recorded against an invoice.

Example: before, the Accounts Payable line of a vendor invoice said "Accounts payable"; now it says "Accounts payable · Vendor invoice B-100 · Acme Supplies Ltd". The Day Book, the General Ledger reports and their downloads show the line memo, so a line read on its own says which document it belongs to.

- **New postings only:** entries posted before R187 keep the memo they were saved with. Posted history is not edited.
- **Manual journal entries** keep the line memos the person typed.

## 2. One Journal Entry menu

General ledger › **Journal Entry** replaces the separate Journal Entries and Expense Vouchers menus. Its tabs:

| Tab | What it records |
|---|---|
| Journal | A manual journal entry (unchanged) |
| Expense voucher | An expense with GST/HST and PST (the former Expense Vouchers screen, unchanged) |
| Interbank transfer | Money moved between two of your own bank or credit-card accounts (new) |
| Import | Balanced journals from a workbook (unchanged) |
| Recurring | Scheduled journals (unchanged) |

**Interbank transfer:**
- Choose the date, the amount, From (money leaves) and To (money arrives).
- Tegh shows the entry and posts one journal entry: a debit to To and a credit to From.
- The server accepts it only when both lines are on the GL accounts of two different active bank or credit-card accounts in the company's own currency, with one debit and one credit of the same amount.
- The entry's memo starts "Interbank transfer".
- When each bank's statement is imported, its line is linked to this entry in Match and Post (Post as › Interbank transfer finds it). It is not posted a second time.

## 3. Interbank transfers in Match and Post

When one unposted line is selected, the posting panel has **Post as: General ledger account | Interbank transfer**.

Interbank transfer lists:
- **Bank GL:** your other bank and credit-card accounts (same currency);
- **Clearing account:** 1070 Interbank Transfer Clearing.

When you choose another bank account, Tegh looks for the other side of the transfer:

| Tegh finds | What happens |
|---|---|
| An unposted line of the same amount, opposite direction, in that bank | One entry is posted for both lines |
| A transfer already posted from that bank | This line is linked to it; nothing new is posted |
| An interbank transfer journal entry | This line is linked to it; nothing new is posted |
| Nothing | One transfer entry is posted. The other bank's line links to it later |

If you say a found item is not the same transfer, Tegh asks why (at least 12 characters) and posts a separate one. Choosing the clearing account posts the line to 1070 with no tax; post the other bank's line to 1070 as well and the two entries offset.

The **Bank Transfers** menu under Banking is removed; it only explained these two methods.

## 4. Reconcile Account finds book entries that match bank lines

A journal entry posted to a bank's GL account (a bank fee or an owner deposit entered by hand, for example) is in the books but not linked to the bank's statement line. Reconcile Account now lists such pairs under **Entries in the books that match bank lines**:
- same amount and direction;
- dated within 7 days of each other;
- closest date first, each line and each entry offered once.

**Match** (or **Match all**) links them through the normal match service. Nothing new is posted, and both leave the reconciling items.

The book balance on Reconcile Account is the live General Ledger balance. The other banking screens also refresh their balances straight after a journal entry is posted.

## 5. One Download menu on reports

- **Report tables** (Bank Reconciliation Report and every other register with the column chooser): the two buttons "Export Current Visible Columns" and "Export All Report Columns" become one **Download ▾** menu with:
  - Excel · visible columns;
  - Excel · all columns;
  - CSV · visible columns;
  - CSV · all columns;
  - Print or save as PDF.
- **Server-built reports:** the Export menu is now **Download ▾** with PDF, Excel, CSV and Print.
- **Bank Reconciliation Report detail:** the separate Download CSV button is removed; CSV is in the menu.
- **Day Book:** it had no download at all. It now has the same Download menu (PDF, Excel, CSV, Print), built from the server's Day Book data for the chosen period and filters.

## 6. Bank Reconciliation Report with nothing to show

When no completed reconciliation matches, the report says: "No completed bank reconciliation to show. This report lists reconciliations after they are completed. Complete one in Banking › Activity › Reconcile Account; it then appears here." An **Open Reconcile Account** button opens it.

## 7. Cancelling an edit stays on the document

Cancel on an edited customer invoice, vendor invoice or note now returns to that document's page instead of the register. Cancel on a new document still returns to the register.

## 8. GIFI Report under Tax Reports

Reports › **Tax Reports** holds the GIFI Report, the Tax Code Report and the GST/HST/PST Summary. GIFI Report is no longer listed under General ledger.

## 9. "+ Add new" in every customer, vendor and GL account drop-down

Every customer, vendor and General Ledger account drop-down now ends with **+ Add new customer…**, **+ Add new vendor…** or **+ Add new GL account…**. Drop-downs that already had their own add option (the invoice editor, for example) keep it.

- **Customer and vendor:** Tegh's existing quick-create form opens. The new record is created through the normal service and selected.
- **GL account:**
  - A short form asks for the type, code and name.
  - The type choices are only the types that drop-down already lists. A vendor invoice line, for example, offers expense and asset.
  - The code is suggested from the codes already used for that type.
  - The account is created through the normal chart-of-accounts service and selected.
- **Cancel** leaves the drop-down as it was.
- **Viewers** see no add options. The server checks permissions in any case.
- **Not included:** bank and payment-account drop-downs. A new bank account is added in Banking › Bank Accounts, because it needs a statement account and currency.

## 10. The old Review Transactions page is removed

Banking used to have two places to post bank lines: Match and Post and an older split page called Review Transactions. The older page is removed. Every link to it opens Match and Post instead, focused on the line when a link names one:
- the dashboard To Do item;
- Tegh Assist;
- saved links;
- record links.

## Notes for the people who maintain Tegh

- **Document reference:** `journal_line_document_reference()` in `api/accounting.php` adds the document reference inside `add_journal_entry()`, before the content hash is calculated, so the stored hash matches the stored lines. It reads the document row. When that row is saved after its journal (issuing an invoice), it reads the entry memo Tegh wrote ("Customer X · Invoice N · …").
- **Interbank transfer journals:** `manual_journal_post_service()` in `api/books.php` validates them when `interbankTransfer` is true; the audit record carries `interbankTransfer`.
- **Matching suggestions:** `tegh_recon_position_r122()` returns `suggestedMatches`.
- **Match and Post:** uses the existing `interbank-v5990` routes (candidates, post-pair, match, adopt) and the existing `transferBankAccountId` posting decision.
- **"+ Add new":** `r187InstallQuickAdd()` in the portal watches the page for drop-downs. It recognises customer and vendor lists by name and GL lists by their "code · name" options, and skips any list that already has a `__create_…` option.

## Testing

| What | Where |
|---|---|
| Suite `61-r187`, the Canadian before/after comparison and the full gate | `beta-gate-evidence/r187/RESULTS.md` |
