# R133 changes over R132: client viewing links

5.9.9 / Build 5990 / Schema 46. Cache token `r133-tegh`. No manual migration: three additive tables are created automatically the first time the feature is used (the database user needs CREATE permission, as for R120).

## What it does
An accountant (company owner or admin) can share a **view-only** dashboard and chosen reports with a bookkeeping client. The client does not need a Tegh account.

1. **Create the link.** In **Settings → Team and access → Client Viewing Links**, enter the client's name and email and tick the reports they may open.
   - **Reports that can be shared:** Profit and Loss, Balance Sheet, Cash Flow, Trial Balance, AR and AP Ageing, GST/HST Summary, the invoice registers, Expense Register, General Ledger Detail and Bank Reconciliation.
   - **Link lifetime:** 7 days to 1 year.
   - **Delivery:** Tegh emails the link to the client, and the accountant can also copy it.
2. **Client signs in with a code.** The client opens the link and clicks **Email me a code**. Tegh emails a 6-digit code to the client's address, valid for 10 minutes. A forwarded link is useless to anyone else, because the code only goes to the client's mailbox.
3. **Client views and downloads.** After entering the code, the client sees:
   - the **dashboard** (money in the bank, customers owe you, you owe suppliers, profit or loss this year, GST/HST), live from the books;
   - the **reports** shared with them, with their own dates, and **Download PDF** / **Download Excel**.

   The viewing session lasts 12 hours. Nothing can be changed.

**Managing links.** The Client Viewing Links list shows each client, their reports, the status (Active / Expired / Turned off), when they last viewed, and the expiry date. Actions:
- **Change Reports:** takes effect immediately.
- **New Link:** the old link and any open session stop working, and the new link is emailed.
- **Turn Off:** signs the client out and stops the link.

## Safeguards
- **Read-only:** the client session can only read the dashboard summary and the shared reports. It is not a Tegh sign-in and is refused by every other API route.
- **Never shared:** payroll, audit history and working papers cannot be shared.
- **Stored as hashes:** link tokens, codes and sessions are stored only as keyed hashes. The link token travels in the URL fragment, so it never reaches server logs or Referer headers.
- **Code limits:** 5 wrong codes lock that code, and a link can request at most 5 codes per hour. Emailed links and codes expire.
- **Accountant access:** if the accountant who shared a link loses owner/admin access to the company, their links stop working.
- **Request checks:** the sign-in requests must come from the same site, and management actions need the owner/admin session plus CSRF.
- **Audit History records:** link created, updated, reissued and turned off; each client sign-in; each report the client opened; and each download.
- **Page headers:** the page is `noindex` and never cached, and follows the app's Content Security Policy (no inline scripts).

## Also fixed: outbound email with an empty reply-to
With the shipped example configuration (`mail.reply_to` empty), every outbound email stopped with "Enter a valid email address" before sending. That included password resets and invitations. The mailer called a validator that exits the request, inside a `try/catch` that could never catch it.

An empty or invalid reply-to now falls back to the sender address, and the sender address is checked without exiting. Servers that already set `reply_to` are unaffected.

## Verified
Tested with a local SMTP catcher, so the real email path ran end to end.
- **Accountant:** created a link, the email arrived with the link, and the link and list appeared. Changing reports, making a new link and turning a link off all work.
- **Client:**
  - **Code sign-in:** the code email arrived, and a wrong code was refused with "4 tries left".
  - **Dashboard:** it showed the same figures as the accountant's dashboard.
  - **Reports:** all 12 shareable reports load with their date controls.
  - **Downloads:** PDF (valid `%PDF` file) and Excel (valid .xlsx) download.
- **Security:**

  | Check | Result |
  |---|---|
  | Editor and viewer managing links | 403 |
  | Payroll report or no reports | 422 |
  | Missing CSRF | 403 |
  | Cross-site sign-in request | 403 |
  | Right code after 5 wrong codes | 429 |
  | Expired code | 410 |
  | 6th code request in an hour | 429 |
  | Report removed while signed in | 403 |
  | Expired link | 410 |
  | Expired session | 401 |
  | Old link and session after "New Link" | stopped |
  | Sharer demoted to viewer | 410 |
  | Client cookie on normal routes (e.g. `auth/me`) | 401 |

  Stored tokens are 64-character hashes, and the plain token is not found in the database.
- **Layout:** desktop and phone (390×844) in light and dark mode, with no sideways scrolling. Wide reports scroll inside their table.
- **Regression:** all menu screens load on desktop and phone with no errors; idle page activity is 1 change; the Tegh Assist test sets pass.

## Files
- **New:**
  - `api/client_view_r133.php`
  - `client-view.html`
  - `assets/tegh-client-view-r133.js`
  - `assets/tegh-client-view-r133.css`
  - `R133-CHANGES.md`
- **Changed:**
  - `api/index.php` (route)
  - `api/portal.php` (mailer address fix)
  - `assets/tegh-portal-v5990.js` (Client Viewing Links page)
  - `assets/tegh-r120.css` (section 30)
  - `.htaccess` (no-store headers for `client-view.html`)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
