# R129 changes over R128: edit bank transactions from the Transactions Report

5.9.9 / Build 5990 / Schema 46. Cache token `r129-tegh`. No database migration.

## Edit an unposted, unmatched bank line
In **Banking > Transactions Report**, select one bank line that is still **pending** (not posted), not matched and not in a completed reconciliation. The selection bar then shows **Edit Transaction** next to Post and View Transaction.

**Edit Transaction** opens a form with:
- Date
- Description
- Reference
- Direction (Money out / Money in)
- Amount, in the bank account's currency
- Remarks (optional)

**Save Changes** stores the edit, reloads the report with the same filters, and shows "Bank Transaction Updated".
- If the amount or description changed, the old category suggestion is cleared, so the line is categorized again from the corrected details.
- Every edit is written to the audit trail (`bank_transaction.edited`) with the before and after values and the list of changed fields.

## When editing is refused
The Edit Transaction button is hidden for lines that can't be edited. The server checks again and refuses:

| Case | Response |
|---|---|
| Posted line (has a journal entry) | 409: "Only unposted transactions can be edited. Posted lines keep their audit trail." |
| Matched line | 409: "This transaction is matched. Unmatch it before editing." |
| Line in a completed reconciliation | 409 |
| Date in a locked period (old or new date) | refused |
| Date in the future | 400 |
| Zero or invalid amount | 422 |
| Empty or too long description (limit 2,000), reference (120) or remarks (500) | 422 |
| Unknown line or another company's line | 404 |
| Users without bank matching permission | refused |

## Also fixed
**Phone header:** on phones, the R128 bank balance in the Match and Post header squeezed the "Bank Transactions" title into a narrow column and made the page 47 px wider than the screen. It now sits on its own line.

## Verified
- **Edit from the report:** a pending line (SQUARE DEPOSIT CAFE, CAD 245.60) was changed to "SQUARE DEPOSIT CAFE - corrected", CAD 250.10, reference SQ-1A, with remarks.
  - The database holds the new values (amount, foreign amount, full description, reference, remarks).
  - The suggestion was cleared.
  - The report shows the new row.
  - The audit entry lists the before and after values.
- **Saving without changes:** returns "no changes" and writes nothing.
- **Selection bar:** a posted line shows only View Transaction and View Journal Entry. A pending line shows Post, Edit Transaction and View Transaction.
- **Refusals:** posted (409), matched (409), future date (400), zero amount (422), unknown line (404).
- **Layout:** the form fits at 1440×900, at 390×844 (full-width sheet) and in dark mode.
- **Regression:**
  - all 46 menu screens load on desktop and phone with no errors or sideways scrolling;
  - idle page activity is still 1 change;
  - PHP and JS syntax checks pass for every file.

## Files
- **Changed:**
  - `api/accounting.php` (new `handle_bank_transaction_edit`)
  - `api/index.php` (route `bank-transactions/edit`)
  - `api/report_loaders_v5980.php` (report rows carry remarks)
  - `assets/tegh-registers-r23.js` (Edit Transaction action and form)
  - `assets/tegh-portal-v5990.js` (`TeghPortal.editBankTransaction`, report reload)
  - `assets/tegh-r120.css` (section 26, phone header fix)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R129-CHANGES.md`
