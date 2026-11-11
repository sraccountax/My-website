# R182 plan — Edit issued customer invoices (QuickBooks-style), with a full audit trail

**Goal:** an issued customer invoice can be corrected in one step: lines, amounts, tax, customer, invoice date, and
(when unpaid) currency and rate. It keeps its number. If the corrected total is less than what the customer already
paid, the difference becomes a **customer credit** (your decision).

Nothing is built yet. This is the plan for your review.

## 1. What Tegh does today (from the code)

| | Today |
|---|---|
| Draft invoice | Fully editable (`tegh_invoice_edit_r20`, `invoice_documents_r20.php`) |
| Issued invoice | Only due date, PO number and message. Everything else is refused: *"Issued amounts, customer, currency and invoice date cannot be changed here. Use the accounting correction workflow."* |
| Correction route | Void and reissue (`voids.php`), which is refused while a payment is applied. Or a credit or debit note (`accounting_notes.php`) |
| Accrual company | Issuing posts one entry: receivables (1200) debit; revenue per line; tax per component (R137 tax codes) |
| Cash-basis company | Issuing posts nothing. Revenue and tax are recognised when each payment is received |
| Customer credit | Already exists: an unapplied customer payment ("Customer advance") sits as a credit in receivables (1200) and can later be applied to an invoice (`payment_apply_service`, `apply_advance_to_document`) |
| Safety features already there | Period locks; revision check (`expectedRevision`); repeat-safe operation keys; "not while sending by email"; audit log with a hash chain; voucher register |

## 2. How the edit will work

### 2.1 What the user sees

1. On an issued invoice, a new **Edit invoice** button (next to Void) opens the normal invoice editor with all
   fields.
2. Before saving, Tegh shows **what will change**: the old and new totals, tax and receivables, the accounting
   entries it will post, and any customer credit.
3. A **reason** is required (for example "Wrong quantity on line 2").
4. **Save correction.** The invoice keeps its number and shows a "Corrected" label with a link to its history.
5. If the invoice was already emailed, Tegh says so and offers **Send corrected copy**. It never re-sends without
   being asked.

### 2.2 What happens in the books (accrual company)

All in one database transaction, so either everything happens or nothing does:

1. **Reverse** the invoice's current entry on its original date (the existing `add_reversing_journal_entry`).
2. **Post** the corrected entry on the invoice's (possibly new) date, with the same posting rules as issuing:
   revenue per line account, and tax per component.
3. Update the invoice, its lines, its tax rows and its voucher; recalculate the open balance.
4. Save the old version in a new **invoice history** table, with who, when, the reason, before and after, and both
   entry numbers.
5. Write `invoice.corrected` to the audit log, with the totals before and after.

Both entries stay visible in the ledger, linked to the invoice. The original entry is never altered or deleted.

| Example | Entries |
|---|---|
| Invoice 1,130 (1,000 + 130 HST) corrected to 904 (800 + 104 HST), unpaid | Reversal: Dr Revenue 1,000, Dr HST 130, Cr AR 1,130. New: Dr AR 904, Cr Revenue 800, Cr HST 104. AR balance 904 |

### 2.3 Paid and partly paid invoices

The payment itself is **never changed**: it stays as received, in the bank and in any reconciliation. Only the
invoice and how much of the payment it absorbs change.

| Case | Result |
|---|---|
| New total **above** what is paid | Invoice re-opens with the remaining balance (status from Paid to Sent) |
| New total **equal to** what is paid | Invoice stays Paid |
| New total **below** what is paid (**your decision**) | Invoice becomes Paid at the new total, and **the difference becomes a customer credit** |

How the customer credit works:
- **In the books:** after the reversal and re-post, the overpaid amount is already a credit in receivables (1200).
  That is exactly where Tegh keeps customer advances today, so no extra account entry is needed.
- **In the customer's records:** the overpaid part of the payment is marked **unapplied**, so the customer's ledger
  shows a credit. It can be applied to another invoice with the existing Apply Payment, or refunded with the existing
  customer refund.

Example: invoice 1,130 fully paid, corrected to 904. Invoice paid at 904; payment of 1,130 shows 904 applied and
**226 available as customer credit**; AR shows −226 for that customer.

*Build step 1 checks this against the payment code before anything else.* The question is whether a payment that was
received against an invoice can carry an unapplied part that `payment_apply_service` and the refund accept. If it
cannot, the fallback is to record the 226 as a separate "customer credit from correction" row, with no bank effect,
that the same Apply and Refund screens use. Either way, the books are identical.

### 2.4 When an edit is refused (with the reason and what to do instead)

| Situation | Why | What the user can do |
|---|---|---|
| The original date or the new date is in a **locked period** | Closed periods must not change | Reopen the period, or issue a credit/debit note in the open period |
| A **credit or debit note** is linked to the invoice | The note's amounts depend on the old lines | Void the note first, or use another note |
| **Currency or exchange rate** change on a paid invoice | The payment and its realised exchange gain/loss were posted at the old rate | Lines and amounts can still be corrected; the rate cannot |
| **Customer** change on a paid invoice | The payment belongs to the original customer | Correct other fields, or unapply the payment first |
| **Cash-basis company**, invoice already paid | Revenue and tax were recognised at each payment, so a correction would have to restate those receipts | Unpaid cash-basis invoices can be edited (nothing has been posted yet); for paid ones, use a credit/debit note. *Full support can follow in a later release* |
| **Opening-balance** invoice, **void** invoice, email **being sent** | Existing rules | Unchanged |
| Someone else changed the invoice meanwhile | Revision check | Reload and edit again |

### 2.5 Tax returns

A correction can change tax for a past period, as in QuickBooks. If the original date is earlier than the company's
last recorded GST/HST remittance, Tegh shows a warning before saving: *"This changes GST/HST for a period you have
already remitted. Lock filed periods to prevent this."* Locked periods are refused outright.

### 2.6 Who can do it

- **Permissions:** *create invoices* (`invoices.write`) **and** *reverse transactions* (`invoices.reverse`, the same
  permission voiding needs). Viewers and editors without the reverse permission see the button disabled, with the
  reason.
- **Switch:** Site & Release Settings › Release › **Edit issued invoices**: off, platform owner only, or everyone.
  **Off by default**, so installing R182 changes nothing until it is turned on.

## 3. Changes, file by file

| File | Change |
|---|---|
| `api/invoice_correction_r182.php` (new) | The correction service: checks, preview, reversal and re-post, payment re-allocation and customer credit, history, audit; routes `invoices/correction/preview` and `invoices/correction` |
| `api/invoice_documents_r20.php` | `tegh_invoice_edit_policy_r20`: issued invoices are sent to the correction service when the switch allows it; otherwise unchanged |
| `api/accounting.php` | Reuse `prepare_invoice_record_r20` and `invoice_posting_lines` (no change in behaviour for issuing) |
| `api/payments.php` | Only if build step 1 shows it is needed: let the unapplied part of a payment be applied and refunded |
| `api/site_settings_r174.php` | Switch `editIssuedInvoices` |
| `api/index.php` | Routes |
| `assets/tegh-portal-v5990.js` | Edit invoice button, editor in correction mode, "what will change" preview, reason field, Corrected label, history view, send corrected copy |
| Database | New table `invoice_revisions` (company, invoice, revision number, before/after snapshot, reason, reversal and new entry IDs, credit created, user, time), added on first use. No existing table changes |

## 4. How it will be tested

1. **Canadian before/after comparison:** the same method as R181 (609 captured reports and screens of 62 companies).
   With the switch off, everything must be identical to R181.
2. **New suite `56-r182`.** Every expected amount is calculated in the test, not taken from Tegh:
   - unpaid accrual invoice: lines and tax changed; reversal and new entry; same number; history; audit;
   - paid → higher total: re-opens with the right balance;
   - paid → lower total: invoice Paid; customer credit of the exact difference; credit applied to another invoice;
     credit refunded;
   - partly paid, each direction;
   - date moved into a different month: entries on the right dates; GST/HST report moves accordingly;
   - refused cases: locked original date, locked new date, linked note, currency change on a paid invoice, customer
     change on a paid invoice, cash-basis paid invoice, opening invoice;
   - foreign-currency unpaid invoice corrected at the R181 recorded rate;
   - two people editing at once (revision check); the same request sent twice creates one correction;
   - permissions: viewer refused; editor without the reverse permission refused; another company's user refused;
   - integrity after every case: debits = credits, AR control = customer subledger, trial balance balances;
   - screens: button, preview, reason required, Corrected label, history, emailed-invoice warning.
3. **Full gate** on the exact ZIP.

## 5. Order of work

1. Confirm in the payment code how the customer credit will be held (section 2.3). If it needs a change in payments,
   say so before building further.
2. Correction service and history table (unpaid accrual first, then paid and partly paid, then the customer credit).
3. Screens.
4. Tests, comparison, full gate, package, results.

## 6. Two points to confirm

1. **Dates of the entries:** reverse on the original date and post on the invoice date, like QuickBooks. This changes
   the original period's figures, and is allowed only while that period is unlocked. The alternative is to post both
   on the day of the correction, which leaves past periods untouched but splits the invoice's history across two
   dates. **Recommended: like QuickBooks.**
2. **Cash-basis companies:** in R182, unpaid invoices only. Paid cash-basis invoices keep using credit/debit notes
   until a later release. **Recommended**, because a correction there has to restate revenue recognised at each
   payment.

Vendor invoices (bills) could get the same feature afterwards. They are not part of R182.
