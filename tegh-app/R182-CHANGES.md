# R182: Correct issued invoices, bank lines entered by hand, and five screen improvements

R182 = R181 plus six changes:

- **A.** Correcting issued customer invoices (behind a switch, off by default).
- **B.** A review after switching between Guided and Full Accounting.
- **C.** A redesigned new-company page.
- **D.** Bank lines entered by hand.
- **E.** Editing bank descriptions.
- **F.** One scroll area in the statement windows.

**After installing, A is off.** Site & Release Settings › Release › **Edit issued invoices**: off, platform owner
only, or everyone. B to F are on for everyone; none of them changes an existing record.

## A. Correct an issued customer invoice

Until now an issued invoice could change only its due date, PO and message. Amounts were changed with a credit or
debit note, or by voiding and reissuing, which is refused once a payment is applied. With the switch on, an owner or
admin can correct the invoice itself, as in QuickBooks.

**How**
1. Open an issued invoice and choose **Correct invoice**, or **Edit** and then **Correct this invoice**.
2. Change any line, quantity, price, tax, GL account, the invoice date or the customer.
3. **Review changes** shows:
   - the before and after totals, tax and open balance;
   - the accounting entries that will be posted;
   - any customer credit.
   This preview is a full run of the correction on the server that is then cancelled, so it shows exactly what saving
   will do.
4. Enter a **reason** (required) and choose **Save correction**.

**What happens**

- **The invoice keeps its number.** It shows a **Corrected** label and a **History** of every correction, with who,
  when, the reason, and the lines and totals before and after.
- **Accrual companies:**
  - The original entry is reversed on its own date.
  - The corrected entry is posted on the invoice date (QuickBooks' approach).
  - Both entries stay in the General Ledger; nothing is deleted or altered.
  - If the date moves, the original month loses the sale and the new month gains it, including GST/HST.
- **Cash-basis companies:** nothing was posted at issue, so only the invoice changes.

**Payments are never changed.** They stay as received, in the bank and in any reconciliation.

| Paid so far compared with the new total | Result |
|---|---|
| Less | The invoice re-opens with the difference to collect |
| Equal | The invoice stays paid |
| More | The invoice is paid at the new total. The excess is released from the payment and stays with the customer as an **unapplied customer credit**. It is already a credit in receivables (1200), so no extra entry is posted. It is used with the existing **Apply Payment** or **customer refund** |

**Refused, with the reason and what to do instead**

| Situation | What to do instead |
|---|---|
| The original or the new date is in a **locked period** | Reopen the period, or use a credit or debit note dated in an open period |
| A **credit or debit note** is linked to the invoice | Void the note first, or issue another note |
| **Customer** change on an invoice with a payment | Unapply the payment first |
| **Currency or exchange rate** change on an invoice with a payment | Keep the currency and rate |
| **Cash basis** and the invoice has a payment (revenue was recognised with each payment) | Use a credit or debit note |
| The new date is **after a payment** applied to the invoice | Use a date on or before the payment |
| A **foreign-currency** invoice corrected below what was paid | Use a credit note |
| **Opening-balance** invoices, **void** invoices, an email **being sent**, or someone else changed the invoice meanwhile | Existing rules apply |

**Who:** the permissions to create invoices and to reverse transactions, which company owners and admins have.
Editors and viewers see the reason they cannot correct.

**Safety**
- Everything happens in one database transaction.
- The same request sent twice saves once.
- Before saving, the server checks that every entry balances and that the invoice agrees with its payments.
- `invoice.corrected` is written to the audit log.

## B. Switching between Guided and Full Accounting

After switching, Tegh opens that mode's home and shows **Where your books stand**, read from the company's records.

- **Guided:** the next step, transactions to review, bank reconciliation and opening balances.
- **Full Accounting:**
  - unposted bank lines and possible duplicates;
  - bank reconciliation;
  - whether receivables and payables agree with the customer and vendor ledgers;
  - overdue invoices, bills due soon and sales tax on the books;
  - opening balances and whether the General Ledger balances;
  - a statement month started in Guided and not yet posted.

Each item that needs attention has a button to the screen that fixes it.

## C. New company page

- **Numbered sections:** Business, Where it operates, Books, Sales tax, What you will use.
- **Optional panels:** Address and contact, and More options.
- A live **summary** and a **What happens next** list.
- A **Create company** button that stays visible.
- **Time zone:** follows the province (for example British Columbia gives Vancouver) until the user picks one.
- **Accounting basis:** chosen with two cards that explain accrual and cash.
- **Payroll posting:** shows only when payroll is chosen.
- **Tutorial:** no longer opens over the form; a **How company setup works** link opens it.
- **Phone:** one column.

Every field, name and rule is the same as before, so the company created is identical.

## D. Bank lines entered by hand

**+ Add transaction** in Match and Post and in the Bank Transaction Report:

- Choose the account, money out or in, amount, date, description, reference and remarks.
- **Repeat every month** for up to 24 months. The day of the month is kept, or the last day for shorter months:
  31 Jul → 31 Aug → 30 Sep.
- Future dates and locked periods are refused.
- The line is added as unposted, so it is matched and posted like an imported one.
- Lines are marked **Entered by hand**.
- When a statement is imported later, a line with the same amount within 5 days of a hand-entered one is flagged as a
  possible duplicate and is left out unless reviewed.

## E. Edit descriptions

- **Match and Post:** a ✎ button on unposted lines changes the description. The amount and date are unchanged.
- **Statement import review:** descriptions are editable in place and are imported as edited.

## F. Statement windows

- **One scroll area.** While the PDF converter or the import review is open, the page behind it does not scroll.
- **Converter:** the header and footer stay in place.
- **After converting a PDF:** the spreadsheet import collapses to one line, and the result with **Use in Upload
  Statement** is brought into view.

## Also fixed

- **Company restore:** a backup could fail to restore when a payment application and its reversal had the same date
  and second, because the reversal was inserted first. Rows are now inserted in the order their references need.
  Company backups also include the invoice correction history.
- **Company Details:** the note under Base currency named CAD for every company. It now names the company's
  currency; Canadian companies see the same words as before.

## Files

| Area | Files |
|---|---|
| Server | New: `api/invoice_correction_r182.php`, `api/banking_entries_r182.php`. Changed: `api/index.php`, `api/backup.php`, `api/invoice_documents_r20.php`, `api/operations.php`, `api/site_settings_r174.php`, `api/portal.php` (both correction entry types are classed as receivables in the Day Book) |
| Browser | New: `assets/tegh-r182.css`. Changed: `assets/tegh-portal-v5990.js`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html` |
| Database | Table `invoice_revisions` is created the first time an invoice is corrected (CREATE privilege, as R174 needed). Nothing is created while the switch is off. No existing table or row changes. Rollback: drop the table. |
| Journal entries | A correction posts two entries: `invoice_correction_reversal` and `invoice_correction`. Both use the correction's own id as source, and their memos name the invoice and the customer. The invoice's voucher points to the corrected entry |
| Cache token | `5990-r182-edit` |

## Limits

- **Vendor invoices (bills)** cannot be corrected this way yet.
- **Cash basis:** invoices with a payment cannot be corrected (use a credit or debit note).
- **Foreign currency:** a foreign-currency invoice cannot be corrected below what was paid, and its currency or rate
  cannot change once paid.
- **Remitted tax:** there is no warning when a correction changes sales tax for a period already remitted. Lock filed
  periods to prevent it; locked periods are refused.
- **General Ledger detail:** the correction entries show the invoice and customer in their memo; the partner column
  is empty for them.
- **Invoice register:** the **Corrected** label is on the invoice screen, not in the register.
- **Emailed invoices:** after a correction Tegh offers to send the corrected copy. It never re-sends on its own.
