# R128 changes over R127: one bank balance, and opening balances after creation

5.9.9 / Build 5990 / Schema 46. Cache token `r128-tegh`. No database migration.

## Match and Post: one balance
The balance row from R127 is reduced to a single figure in the Bank Transactions header: **Bank balance · To date**.
- The figure is the account's opening balance plus every imported statement line up to the To date. Lines marked duplicate or excluded are left out.
- It shows in red when negative.
- Opening, money in, money out, book balance and difference were removed.

## Customer and vendor opening balances
**What we found:** creating a customer with an opening balance does post to the General Ledger. On a test customer created through the form, $1,250.00 was posted as Dr 1200 Accounts Receivable / Cr 9999 Opening Balance Control. The gaps were elsewhere:
- **No way to add it later:** once a customer or vendor existed, there was no way to add an opening balance. The edit screen showed $0.00 read-only, and the server refused it.
- **Edit screen labelled as new:** the edit screen was titled "New Customer" / "New Vendor" with a "Create customer" button, even when editing an existing record.

**What changed:**
- **New button:** the customer and vendor edit screens now have a **Post opening balance** button (right-hand column) until an opening balance exists. It takes an amount and a balance date.
  - Positive: the customer owes you, or you owe the vendor. Negative: a credit balance.
  - It asks for confirmation, then posts Dr/Cr the AR (1200) or AP (2050) control account against Opening Balance Control (9999), and registers an AROB/APOB voucher.
- **One posting per party:** once posted, the screen shows the amount and date read-only. A second posting is refused, and corrections use an adjusting entry, as before.
- **Validation:** the server rejects a zero amount, a missing or future date, a record in another company, and users without customer or vendor write permission.
- **Titles fixed:** the edit screens are titled **Edit Customer** / **Edit Vendor**, and the button reads **Save changes**.
- **New endpoint:** `POST party-opening-balance` (`api/master_data.php`), using the existing `post_party_opening_balance` service.

## Verified
- **Customer, posted later:** a customer created without a balance, then posted with the button: $1,480.25 posted as Dr 1200 / Cr 9999, voucher AROB-002. The screen then showed it read-only.
- **Vendor:** $735.50 posted as Dr 9999 / Cr 2050, voucher APOB-001.
- **Refusals:** a second posting (409), no date (422), zero (422), a future date (400) and an unknown customer (404).
- **Edits:** editing and saving a customer's phone still works. New Customer still shows "New Customer" / "Create Customer".
- **Match and Post:** shows "Bank balance · Sep 28, 2026 −$704.20" in red.
- **Regression:**
  - all 46 menu screens load on desktop and phone with no errors;
  - idle page activity is still 1 change;
  - PHP and JS syntax checks pass for every file.

## Files
- **Changed:**
  - `api/master_data.php` (new handler)
  - `api/index.php` (route)
  - `assets/tegh-portal-v5990.js` (balance figure, opening balance button)
  - `assets/tegh-reference-r29.js` (edit titles, button placement)
  - `assets/tegh-r120.css` (section 25)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R128-CHANGES.md`
