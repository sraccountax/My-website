# R122 changes over R121

5.9.9 / Build 5990 / Schema 46. Cache token `r122-tegh`. No database migration.

## Menus and brand

- **Sidebar labels are shorter and always fit on one line.**
  - Banking: **Match and Post**, **Reconcile Account**, **Bank Accounts**, **Transactions Report**, **Bank General Ledger** and **Bank Reconciliation**.
  - Receivables and Payables: **Invoice Register**, **Products**, **Payments** and **Trial Balance**.
  - General Ledger: **General Ledger**.
  - Internal menu keys are unchanged, so saved quick actions, search and permissions keep working.
- **Brand block redesigned:** the mark and a spaced "TEGH" wordmark sit on the first row, with the tagline on its own line underneath and a small accent rule. The collapse button is pinned top-right.
- **"SR Books" removed:**
  - **Renamed:** JavaScript globals (for example `TeghPortal`), saved-preference keys, email boundaries, and the backup format written from now on (`tegh-backup`).
  - **Migrated once in the browser:** preferences stored under the old name move to the new keys, so report periods, the sidebar state and tutorial progress are kept.
  - **Kept for compatibility:** the old name appears only inside compatibility code, which lets old `.srbooks` backups restore and renames an old "SR Books Standard" invoice template on upgrade.

## Reports: actions for the selected row

Ticking a row in a report now shows the actions that fit that record and its status, above the table.

- **Invoices:**
  - Draft: View, Edit, Record and Post.
  - Posted and open: View, Record Payment, Credit Note, Debit Note, View Journal Entry.
- **Vendor invoices:** the same actions (View, Edit, Record and Post; Record Payment, Supplier Credit Note, Supplier Debit Note).
- **Credit and debit notes:** Open Note. A draft also offers **Post Note**, which targets that exact note.
- **Bank transactions:**
  - Unposted: **Post**.
  - Posted but unmatched: **Match**.
  - Excluded: **Restore**.
  - Every line: View and View Journal Entry.
  - Several lines from the same account can be posted or matched together; Match and Post opens with them already selected.
- **Ageing, balances, customer and vendor lists:** view the document, Record Payment, open the party's ledger, or edit the customer or vendor.
- **Trial balance, balance sheet, profit and loss:** Open Account Ledger.
- **General ledger, bank ledger, expenses, remittances:** View Journal Entry.
- **Day Book:** each voucher offers **Open Source Record**.

Every action opens the normal Tegh screen for the record, so posting and editing still go through that screen's own checks and confirmation.

## Bank reconciliation calculated from the books

The reconciliation no longer asks for, or reads, an opening or closing balance from a statement import.

- **Bank balance on a date:** the account's opening balance (from Opening Balances) plus every imported bank transaction up to that date. Lines marked excluded or duplicate are left out.
- **Book balance:** the General Ledger balance of the bank account on the same date.
- **The difference is broken into reconciling items:**
  - imported bank transactions not yet posted;
  - transactions on the bank by the period end but posted in the books after it;
  - book entries with no bank transaction (cheques not cleared, deposits in transit, manual entries);
  - book entries by the period end whose bank transaction is dated after it.
- **Reconcile Account screen:**
  - Cards: bank balance, book balance, difference, and the unexplained part.
  - A reconciliation statement with each group of items.
  - The bank's running balance through the period.
  - A **Post** button on unposted lines.
  - **Complete** is available when the unexplained difference is $0.00; reconciling items are allowed and are recorded.
- **Bank Reconciliation report:** shows the same bridge and lists the reconciling items. "Cleared" now means posted and in the books by the period end.
- **Verified:**
  - Business Chequing on Sep 27 calculates to $1,058.75, which matches the statement's own closing balance.
  - On Sep 28 the $1,538.27 difference is fully explained: two unposted imports plus the CRA payroll remittance recorded only in the books.

## Remaining limits from R121, now fixed

- **GST/HST remittance with input tax credits:**
  - When a bank line is posted to "GST/HST remittance or refund", a **Return period end** field clears the ITCs recorded to that date (Cr 1100). GST/HST Payable takes the balancing amount (Dr 2100), and the preview shows all three lines.
  - A later payment for the same period cannot clear the same credits twice.
  - The same applies to PST when PST is recoverable.
- **CRA payroll remittance:** income tax, CPP/CPP2 and EI now prefill from the payroll liabilities outstanding at the period end. Typed values are never overwritten.
- **Credit and debit note PDFs:** every note has a **PDF** button, for credit notes, debit notes and supplier credit and debit notes.
  - The PDF uses the invoice layout: company, customer or vendor, lines, tax, credit total, applied/refunded, remaining, and the original document.
  - A draft is watermarked "DRAFT — NOT POSTED".

## Flicker and layout fixes

- **Flickering headings:** two layers set page headings differently, so Edit Product switched between "Edit item" and "Edit Product or Service" about 60 times a second. Headings are now written only when the text actually changes, and the two titles agree.
  - A detector checking every menu screen, every New/Add/Create form and all Settings pages finds no remaining flicker.
- **Party ledgers:** opening a customer or vendor ledger showed the internal page id ("Ledger-customer") as the title. It now shows the customer or vendor name. This was also broken in R118.
- **Reconciliation report:** its detail sections were squeezed to 16px bars and now display in full.

## Lighter package

- 104 unused files were removed, and the package drops from 42 MB to 30 MB unpacked. Nothing in the app, `.htaccess` or the API references them:
  - superseded asset versions: portal, gate, preflight, CSS, PDF, output, bank converter, sites shell and viewport;
  - unused SVGs;
  - release notes from R9 to R28, and the old 5970 and 5980 release-evidence folders.
- **Host copies:** files already on the host may stay; they are not loaded. Leaving them does no harm; removing them is optional.
- **`.htaccess`:** the old "srbooks-login" redirect was removed. The rule that denies stray old test files is kept, written generically.

## Verified

Checked with Playwright (Chromium) on local PHP 8.4 and MariaDB.

- **Screens:** all 46 menu screens at 1440×900 and 390×844, with no errors and no sideways drift.
- **Idle DOM activity:** still 1 change across all screens.
- **Dropdowns:** 375 triggers checked, with no new problems.
- **Sidebar:** every label fits on one line, even with a module open and its scrollbar showing.
- **Earlier releases:** R119/R120 tabs and the note form still pass.
- **Exports:** PDF, Excel and CSV.
- **Row actions:**
  - **Draft invoice:** Edit opens the editor, and Record and Post reaches its confirmation.
  - **Bank transactions:** two pending lines open preselected in Match and Post.
  - **Draft credit note:** Post Note confirms the selected note.
- **GST/HST remittance:** Dr 2100 $2,579.67 / Cr 1100 $1,079.67 / Cr bank $1,500.00.
- **Payroll remittance prefill:** $683.75 / $522.62 / $182.35.
- **Note PDFs:** credit note and supplier credit note.
- **Reconciliation:** a draft saved and a completion accepted.

## Files

- **New:**
  - `api/reconciliation_position_r122.php`
  - `R122-CHANGES.md`
- **Changed:**
  - `.htaccess`
  - `api/accounting.php`
  - `api/backup.php`
  - `api/bank_operations_v5980.php`
  - `api/operations.php`
  - `api/portal.php`
  - `api/report_loaders_v5980.php`
  - `app.html`
  - `assets/tegh-activity-r22.js`
  - `assets/tegh-gate-v5990.js`
  - `assets/tegh-modern-v5700.js`
  - `assets/tegh-pdf-v5990.js`
  - `assets/tegh-portal-v5990.js`
  - `assets/tegh-preflight-v5990.js`
  - `assets/tegh-professional-output-v5990.js`
  - `assets/tegh-r120.css`
  - `assets/tegh-reference-r29.js`
  - `assets/tegh-registers-r23.js`
  - other files where the old global names were renamed
- The full list is in `PACKAGE-MANIFEST.json` under `r122Files`.
