# R165 — report row actions open the record they name

R165 = R164-1_1 (the build supplied on 2026-10-22) plus fixes to row actions. Server code, accounting and the database
are unchanged; only browser files changed.

## The problem

In the Vendor Invoice & Note Register and the Customer Invoice & Note Register, some row actions looked as if they did
nothing:

- **View** on a vendor invoice re-opened the same register with that row highlighted. The only visible change was a
  taller row and a one-line note above the table.
- **Open note** on a credit or debit note (CN, DN, SCN, SDN) opened the note register with that row highlighted, in the
  same way.
- The same happened from the selection bar (**View vendor invoice**), from the Day Book and ledgers ("open source
  document" for a vendor invoice or a note) and from agent findings that pointed to a vendor invoice or a customer
  invoice.

## What changed

- **New document page** for a vendor invoice or a credit/debit note. It shows the vendor, customer or supplier; the
  dates; the original invoice (for a note, as a link); currency and posting status; the lines; subtotal, tax and
  total; paid or applied, and the open balance or remaining amount; and the memo. Amounts are the stored document
  amounts (the vendor invoice uses the same server-authorised document output as its PDF), so nothing is recalculated
  in the browser.
- Actions on that page:
  - **Vendor invoice:** PDF, Print, Day Book entry, Edit (drafts) or Edit dates and notes (posted), Record payment
    (open balance) and Open in register.
  - **Note:** PDF, Day Book entry, Edit (drafts) and Open in note register.
  - Each action shows only when the person's role allows it.
- **Report row menus:**
  - Vendor invoices: **View vendor invoice** replaces the old "View".
  - Notes: **View note** replaces "Open note".
  - **Open in note register** remains for posting or applying a note.
  - Clicking a note number opens the note.
- **Selection bar:** View vendor invoice and View note open the document. **Post note** still opens the note register
  and its own Post button.
- **Day Book, ledgers and agent findings:**
  - A vendor invoice or a note opens its document page.
  - A customer invoice opens its invoice details page.
- **Credit & Debit Notes screens:** each note has a **View** button.
- **Day Book:** a transaction's ⋮ menu now offers **Open source document**, which opens the invoice, vendor invoice,
  note, bank line, payment, expense or pay run behind it. Before, the menu only showed or hid the ledger lines.
- **General Ledger Account Report:** the selection bar no longer offers **Open Account Ledger**, which re-opened the
  same report. **View Journal Entry** remains.
- A voided bank line in the Day Book or a ledger opens the bank line; before, it showed "Source workspace
  unavailable".
- **Void message:** voiding a customer invoice that has a linked note now says "This cannot be voided yet" and tells you
  to void the note first. It used to say "This transaction cannot be checked".
- Cache token `5990-r165-views` makes browsers load the new files.

## Checked and working (no change)

These row actions were checked for every report and gave a visible result, so they were left as they were:

- Edit and View invoice details.
- Invoice PDF, Print and Excel.
- View Day Book Entry.
- Apply Open Payment.
- Void/reverse, which opens the void confirmation, or says why the document cannot be voided yet.
- Ledger and ageing actions, and showing or hiding the Day Book ledger lines.

The results of every action are in `beta-gate-evidence/r165/`.

## Files

- `assets/tegh-portal-v5990.js`: document page; View, open-source and agent-finding routes (including voided bank
  lines); View on the note screens; void message.
- `assets/tegh-registers-r23.js`: row menu, selection bar and Day Book menu actions.
- `assets/tegh-foundation-r22.css`: two small rules for the document page.
- `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`: cache token.
- Manifests and these notes.
