# R151 changes over R150: one document form, itemized vendor invoices, in-page editing, scrolling fixes

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r151-tegh`.
Status: **staging candidate**, `productionReady: false`.

## Owner requests (2026-10-05)

### 1. Adding a line no longer scrolls the page to the top
- Every "Add" click used to rebuild the whole invoice form. That reset the page to the top.
- In the new form, adding, removing or changing a line touches only that row, so the page stays where it is.
- Pressing Enter on the last row adds a new line, like a spreadsheet.

### 2. One form for invoices, credit notes and debit notes, on the customer and vendor sides
- **Document type** is a drop-down at the top: Invoice, Credit note or Debit note. On the vendor side these are Vendor invoice, Supplier credit note and Supplier debit note. The separate tabs are no longer used to create documents.
- **Top section:**
  - customer or vendor, with "+ Add new…" still available in the drop-down;
  - invoice number, or the original invoice number for a note;
  - dates and terms;
  - template and PO for customer invoices;
  - whether vendor line amounts are before tax or include tax;
  - currency and exchange rate.
- **Lines** sit below, in a highlighted spreadsheet-style grid: #, Product/service, Description, Qty, Rate, Tax, Amount, Total, GL account. Subtotal, tax and total are shown under the grid.
- **No right-hand summary panel.** The form uses the full width.
- **On phones** the grid scrolls sideways inside its own box.

### 3. Credit and debit notes: type the invoice number or choose one
- The "Original invoice number" field accepts typing and also lists that customer's or vendor's invoices.
- **The number matches an invoice in Tegh:** the note is linked to it. Tax follows the original, as before, and "Return items from INV-…" fills the lines from the original.
- **The number is not in Tegh** (for example, an invoice from before Tegh):
  - the note is saved against the customer or vendor, with that number as its reference;
  - each line has its own tax and GL account;
  - a credit note posts as an open credit on the account, which can then be applied to an invoice or refunded;
  - a customer debit note creates its own amount due.
- **GL account per line.** Every note line has one. Linked notes also post to the account chosen on each line. Before R151 they used the original invoice's revenue split.

### 4. Vendor invoices with several lines, each with its own GL account and tax
- **Before R151**, the extra lines on the vendor invoice screen were text in the memo. The invoice posted one amount, to one account, with one tax setting.
- **Now** each line is stored and posted to its own expense or asset account.
  - Recoverable tax goes to its tax account.
  - Tax that can't be claimed back (for example, PST) is added to that line's account.
- **Cash-basis payments** recognise each line's account in proportion, to the cent.
- **The vendor invoice PDF** prints the lines with their GL accounts.
- **Unchanged:** single-amount vendor invoices (imports, recurring schedules, Document Intake, Tegh Assist) work as before. "Make this a recurring vendor invoice" is still on the form.

### 5. Edit on the registers opens the document on the main screen (no pop-up)
- **Where:** the Customer Invoice & Note Register and the Vendor Invoice & Note Register row Actions menus have **Edit**. So do the invoice registers' action columns, the note register's draft notes, and the invoice detail page. All open the same full-page form; the invoice pop-up editor is no longer used.
- **Drafts** can be changed in full.
- **Issued documents** can change only their due date, PO and message (invoices) or due date and memo (vendor invoices). Amounts and lines stay as posted; use a credit or debit note, or void, to change them. This follows the existing audit rules, as the owner chose.
- **Posted notes** cannot be edited. Void them and create a new one.

### 6. Sidebar menu scrolling
- **Cause:** right after a menu item was chosen, the browser could keep a stale scroll target, so the next scroll over the menu was lost.
- **Fix:** a wheel or trackpad scroll over the menu now moves the menu by exactly the scrolled amount.
- **Sizing:** the sidebar is sized to the visible screen (`100dvh`, so phone browser bars no longer hide its bottom).
- **Open sections:** these no longer have a separate inner scroll box.

## Database (automatic, on first use)
- **New table** `bill_lines`.
- `accounting_notes`:
  - new column `reference_number`;
  - `source_id` now allows NULL, for notes whose original is not in Tegh.
- `accounting_note_lines`: new columns `account_id`, `tax_code_id`, `apply_gst`, `apply_pst` and `foreign_tax_cents`.
- **Backups** now include `bill_lines` and `accounting_note_lines`. Note lines were missing from backups before R151.

## Files
**API**
- `api/documents_r151.php` (new)
- `api/books.php`
- `api/accounting.php`
- `api/accounting_notes.php`
- `api/backup.php`
- `api/index.php`
- `api/report_loaders_v5980.php`
- `api/report_subledgers_v5980.php`

**App and assets**
- `app.html`
- `assets/tegh-portal-v5990.js`
- `assets/tegh-registers-r23.js`
- `assets/tegh-invoice-reports-r20.js`
- `assets/tegh-r120.css`
- `assets/tegh-gate-v5990.js`
- `assets/tegh-preflight-v5990.js`

**Docs and manifests**
- `R151-CHANGES.md`
- README
- DEPLOYMENT-NOTES
- the manifests
