# R119 changes over R118

5.9.9 / Build 5990 / Schema 46. No database migration. Cache token `r119-notes-register-mobile`.

## Accounting fixes (PHP)

- **New invoices blocked credit and debit notes.** `create_invoice_record()` and the R20 invoice edit never wrote the GST/HST and PST split (`gst_hst_cents`, `pst_cents`, `tax_entry_mode`) added in Schema 46. Every taxed note against an invoice created after the upgrade failed with `note_source_tax_unclassified`.
  - `api/accounting.php`: new `invoice_record_tax_split()` stamps the split when an invoice is saved. All sales tax goes to GST/HST, matching `invoice_posting_lines()`, which posts all tax to account 2100.
  - `api/invoice_documents_r20.php`: the same on invoice edit.
  - `api/accounting_notes.php`: `note_source()` repairs an older unsplit invoice from its own posted journal (2100/2110 lines) before a note is posted. It never guesses a split.
- **Sign-in was blocked by "Database upgrade required".** The startup preflight counted those unsplit invoices as `unclassified_issued_tax` with `repairable:false`. After any taxed invoice was issued, the platform owner got a blocking upgrade screen that could not complete.
  - `api/migration_schema46_notes_r67.php`: the preflight now runs the same deterministic, journal-based backfill the R69 upgrade used. That backfill is idempotent and skipped when nothing is pending. Only invoices whose split truly cannot be recovered are still reported.

## Invoice | Credit Note | Debit Note tabs

- `assets/tegh-portal-v5990.js`: the customer invoice screen shows **Customer Invoice | Credit Note | Debit Note**. The vendor invoice screen shows **Vendor Invoice | Supplier Credit Note | Supplier Debit Note**.
  - The tabs use Banking's "Post transactions / Match transactions" segmented control (`.r28-mode-question`).
  - They sit in the page header, which the R22 shell keeps across re-renders. R118's strip sat above the title, and the R22 shell moved anything placed in the body below the form.
- The note tab sets the note type. The separate "Note type" dropdown is gone, and each tab lists only its own notes.
- Posting or voiding a note reloads onto the same tab.
- Switching tabs still asks before discarding an unsaved form.
- Activity menus no longer list Customer/Supplier Credit Notes and Debit Notes separately. The old names still work from search and commands.

## One register per side for invoices and notes

- `api/report_loaders_v5980.php`: the `invoice-register` and `bill-register` models now also include accounting notes.
  - The registers are titled **Customer Invoice & Note Register** and **Vendor Invoice & Note Register**.
  - New **Document Type** and **Original Document** columns.
  - Credit notes and supplier debit notes are negative, so totals are net. Their outstanding amount is the unapplied credit.
  - A posted customer debit note already exists as its own receivable invoice row. That row is labelled "Debit Note" and the note is not listed twice.
  - Status, search and customer/vendor filters apply to note rows too. The "Overdue" filter lists invoices only.
- `api/report_output_v5980.php`: definition titles renamed, so exports and PDFs carry the new names.
- `assets/tegh-registers-r23.js`: Document Type and Original Document columns are shown by default, and added once to saved views. Clicking a note number, or choosing "Open note", opens the matching note tab with that note highlighted.
- The menus show one register under Receivables → Reports and one under Payables → Reports. The old Invoice Register and Note Register names are aliases.

## Scrolling and tables

- `assets/tegh-registers-r23.js`: report/register tables no longer hide columns to fit the window.
  - Every chosen column keeps a readable width, sized from its content (text columns are capped at 240/360px).
  - When the window is narrower than the table (zoomed in, small window, phone), the table scrolls sideways.
  - When it is wider (zoomed out, large monitor), columns stretch, and because the table fills the remaining height, more rows show.
  - Measured on a 1440×900 screen, Customer Invoice & Note Register:
    - 67% zoom: 20 rows, all 12 columns visible.
    - 100%: 10 rows, sideways scroll.
    - 150%: sideways scroll.
- `assets/tegh-r119.css` (new, loaded last): always-visible styled scrollbars on report tables and denser rows (45px instead of 53px).

## Report generation

- **Exports blocked the next export.** Every export showed a "Download ready" card for two minutes in the top-right corner, and several exports stacked. The cards covered the report's Actions → Export menu, and on a phone they spanned the whole top of the screen.
  - `assets/tegh-downloads-r15.js`: keeps at most two notices and closes each after 20 seconds. The saved copy remains under Settings → Requested Downloads.
  - `assets/tegh-r119.css`: notices sit at the bottom, above the phone's bottom navigation.
- **Register PDFs were not tables.** Any view with more than 6 columns printed as one "Field / Value" block per record.
  - `assets/tegh-registers-r23.js`: views of up to 12 columns now print as a landscape table (the Invoice & Note Register has 11). Wider views still use record details.
- **PDF rows split across page breaks** (a date split as "May 6," / "2026").
  - `assets/tegh-pdf-v5990.js`: a row of up to 6 lines moves to the next page whole.
  - `assets/tegh-professional-output-v5990.js`: loads the PDF engine with token `?v=5990-r119`.

## Mobile (≤ 820px)

The R30 mobile layer released only pages tagged "report" from the desktop fixed-height layout. On a phone the following showed a blank or 0–76px content area:

- Customer Invoice (new invoice form)
- Vendor Invoice
- Customer and Vendor registers
- Customers
- Customer Payments
- Products and Services
- Bank Transaction, Bank General Ledger and Bank Reconciliation reports
- Match And Post Transactions, where the two panels were 2px tall and drew over each other

Report exports were unreachable on these pages because the table and its Actions → Export menu were collapsed.

`assets/tegh-r119.css`:

- All ordinary pages flow with the phone's page scroller. The bank review queue and the dashboard keep their bounded layouts.
- Match And Post stacks the transaction list above the posting/match panel.
- Page heads put the title on one row and page actions on the next. "New Customer / New Invoice" no longer covers the From/To filters.
- Registers stay a real table that scrolls sideways instead of R30's record cards. Day Book keeps its own layout.
- The invoice/note tabs become three equal buttons.

Checked with Playwright (Chromium) at 390×844 against a local PHP 8.4 + MariaDB copy with seeded invoices, bills and notes:

- Every Reports/Receivables/Payables/Banking page renders.
- Excel, CSV and PDF export three times in a row without a blocked menu for:
  - Customer Invoice & Note Register
  - Vendor Invoice & Note Register
  - Profit and Loss
  - Balance Sheet
- Desktop (1440×900) page layouts match R118 apart from the tables.

## Files

- Changed:
  - `api/accounting.php`
  - `api/accounting_notes.php`
  - `api/invoice_documents_r20.php`
  - `api/migration_schema46_notes_r67.php`
  - `api/report_loaders_v5980.php`
  - `api/report_output_v5980.php`
  - `app.html`
  - `assets/tegh-gate-v5990.js`
  - `assets/tegh-preflight-v5990.js`
  - `assets/tegh-portal-v5990.js`
  - `assets/tegh-registers-r23.js`
  - `assets/tegh-workflow-v5700.js`
  - `assets/tegh-downloads-r15.js`
  - `assets/tegh-pdf-v5990.js`
  - `assets/tegh-professional-output-v5990.js`
- New: `assets/tegh-r119.css`.
- Also updated: `R119-CHANGES.md`, `DEPLOYMENT-NOTES.txt`, `PACKAGE-MANIFEST.json`, `tegh-build.json`, `FILE-MANIFEST.sha256`, `README.txt`.

Upload them together. The gate, portal and workflow scripts are served `immutable`, so only the new cache token in `app.html` and the gate makes browsers fetch them.

## Not changed / still open

- Credit and debit notes still have no PDF of their own (none existed before).
- Bank Reconciliation Report and Products and Services draw their empty-state row slightly wider than a phone. It is clipped, not scrollable, and there is no content loss.
- The R118 open items (Clarity disclosure, hard-coded tax account codes, staging robots/sitemap) are unchanged.
