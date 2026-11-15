# R186: Unpost, edit and delete in Match and Post

R186 = R185 plus three actions in **Banking › Match and Post**. There is no switch and no database change.

## What you see

Each line in the Bank Transactions list has one **Actions ▾** menu, at the right of its description. It appears on the selected line: a line you tick, or a line you click. Other lines stay uncluttered. Keyboard users reach it with Tab, and Escape closes it.

| Line | Actions ▾ offers |
|---|---|
| Unposted | **Edit**, **Delete** |
| Posted | **Unpost** |

People who may not change banking do not see the menu. The server checks permissions again in any case.

### Lines linked to an invoice or bill

A line linked to a customer invoice or vendor bill **cannot be edited, deleted, unposted or voided**. Its Actions ▾ menu says: "Linked to a customer invoice or vendor bill. It cannot be edited, deleted or unposted." The server refuses these actions too, whatever screen or tool asks.

**The Company Owner's correction.** Only the Company Owner (not a Company Admin) sees **Correct with journal entry** in that menu. It:
- reverses the receipt or payment with a journal entry dated on the statement date (nothing is deleted);
- reopens the invoice or bill for the amount it had before;
- returns the line to Unposted, so it can be posted to the right invoice, bill or account.

The dialog shows these effects and the entry that leaves the reports, and asks for a reason of at least 12 characters. The audit record marks it as an owner correction and names the invoice or bill. Above the approval limit a second Company Owner approves it. A line matched to a receipt or payment entered in Payments only loses its link: that receipt or payment stays posted and the invoice or bill stays paid.

Everyone else sees "Only the Company Owner can correct it." Edit and Delete stay refused for everyone, the Owner included.

A line counts as linked when the receipt or payment recorded from it, or matched to it, belongs to an invoice or bill. That covers a line:
- posted as the receipt of an invoice or the payment of a bill;
- matched to a receipt or payment entered in Payments for an invoice or bill;
- posted as an on-account receipt or payment that was later applied to an invoice or bill.

An on-account receipt or payment that is not applied to any invoice or bill is not linked, so it can still be unposted.

### Edit an unposted line

The edit dialog changes:
- money in or out;
- amount;
- date;
- description, reference and remarks.

This replaces R182's ✎ pencil, which could change only the description, reference and remarks.

Rules:
- The audit history keeps the old and new values.
- A line that is matched, in a completed reconciliation, or dated in a closed period cannot change. These rules were already in R129.

### Delete an unposted line

- Tegh asks for confirmation, then deletes the line.
- No ledger entry is involved, because the line was never posted.
- An imported line comes back only by importing the statement again.

### Unpost a posted line

Unpost undoes the posting and puts the line back in **Unposted**, where it can be posted again.

Before anything changes, a dialog shows:
- what will happen, in plain words;
- the entry that leaves the reports.

You give a reason of at least 12 characters, which is kept in the audit history, and confirm.

**What Unpost does for each kind of posting:**

| The line was posted… | Unpost |
|---|---|
| Straight to an account (expense, income, transfer to a non-bank account…) | Voids the entry, exactly as Void does. It leaves the trial balance, statements, ledgers and tax figures, and stays in the Day Book. |
| As an on-account receipt or payment, not applied to any invoice or bill | Reverses it, dated on the statement date. |
| As a match to a payment entered earlier in Payments that is not for an invoice or bill | Removes only the link. The payment stays posted. If the match had moved the money out of Undeposited Funds, that transfer is voided. |
| As the receipt of an invoice or payment of a bill, or matched to one | Refused: the line is linked to an invoice or bill. Only the Company Owner can undo it, with **Correct with journal entry** (see above). |

**Unpost refuses, with the reason, when:**
- the line is **linked to a customer invoice or vendor bill**, unless the Company Owner corrects it (see above);
- the line is in a **closed period**;
- it is part of a **saved reconciliation**;
- it is matched in **Bank Reconciliation**;
- it belongs to an **interbank transfer** (use Unmatch or Reverse transfer on that line instead);
- the line was recorded by another part of Tegh, such as payroll.

**Who can unpost:**
- People with permission to reverse banking transactions: the Company Owner and Company Admin. This is the same permission as Void.
- Editors and Viewers cannot.
- Above the company's approval limit (10,000.00 by default), the request is saved, and a different Company Owner approves it before the books change, as with Void.

## A correction to Void

Before R186, **Void** on the Bank Transactions list could be used on a line that had been posted as the receipt of an invoice or the payment of a bill. Voiding it took the entry out of the ledger but left the invoice or bill marked **paid**. The receivable or payable list then no longer agreed with the General Ledger.

R186 refuses that Void: a line linked to an invoice or bill cannot be voided, edited, deleted or unposted. Only the Company Owner can correct it, with Correct with journal entry, which reverses the receipt or payment and reopens the invoice or bill together.

**Check after deploying:** look for any line voided this way before R186. In the Bank Transactions list, filter **Excluded**, and look for lines whose invoice or bill still shows as paid. To correct one, open the invoice or bill and reverse its payment.

## Notes for the people who maintain Tegh

**New server files and routes**
- New file `api/bank_unpost_r186.php`, with two routes:
  - `bank-transactions/unpost-preview` (GET), which changes nothing;
  - `bank-transactions/unpost` (POST), which needs an operation key. A retried request returns the first result.

**The linked rule**
- `r186_bank_line_document_link()` in `api/bank_unpost_r186.php` decides whether a line is linked to an invoice or bill.
- Unpost, Edit (`bank-transactions/edit`), Delete (`bank-transactions/delete`, also used by Ask Tegh) and Void all refuse a linked line with code `bank_transaction_linked_document` (Void and Unpost list it as a blocker).
- The Owner's correction is the Unpost route (`bank-transactions/unpost`) when the caller's company role is exactly `owner`. The preview returns `ownerCorrection: true` and `linkedDocument`, and the audit record `bank_transaction.unposted` carries `ownerCorrection: true`.
- The Match and Post workspace returns `linkedDocument` for each line, which the Actions menu uses.

**Shared payment reversal**
- The accounting steps of reversing a payment now live in `party_payment_reverse_core()` in `payments.php`.
- The Payments screen uses it and behaves as before.
- The Owner's correction of an invoice receipt or bill payment uses it too.

**Keeping the line postable again**
- An unposted line's old journal keeps its content and hash.
- Only its source label changes, to `bank_transaction_voided` or `bank_payment_match_voided`, so the line can be posted again. Restore already did this in R162.
- The reversed payment drops its link to the statement line for the same reason. The link is kept in the audit record.

**Match and Post data**
- The workspace now also returns each line's amount in the account's currency, its currency and its remarks.
- Before, the R182 pencil sent the base-currency amount back for lines in a foreign-currency account.

## Testing

| What | Where |
|---|---|
| Suite `60-r186` (19 checks), the Canadian before/after comparison and the full gate | `beta-gate-evidence/r186/RESULTS.md` |

This release has not been tested on the IONOS server.
