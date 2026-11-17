# R170 — Invoice Intelligence (beta)

R170 = R169 plus Invoice Intelligence in Payables. Upload supplier invoice PDFs. Tegh reads them in your browser and
checks every figure. You review the result side by side with the document, and it becomes a **draft** vendor invoice
through the normal vendor invoice service. A draft posts nothing. You issue it from the Vendor Invoice & Note Register
through the usual approval workflow.

Design, findings and limits: `R170-INVOICE-INTELLIGENCE-ARCHITECTURE.md`.
Tables and rollback: `R170-MIGRATION.md`.
Running it, recovery and backups: `R170-OPERATIONS.md`.

## Where it is

- **Payables › Invoice Intelligence** in the sidebar and in top navigation.
- In Guided mode: **Money Out › Read Supplier Bills**.
- Tegh Assist answers vendor invoice questions such as:
  - "Show possible duplicate invoices"
  - "Find invoices missing GL accounts"
  - "Why did you select Office Supplies?"
  - "Which invoices need my approval?"
  - "Review Northgate's invoice history"
  - "Prepare these invoices as drafts"
- A vendor invoice or supplier credit note drafted this way has an **Original PDF** button.

## Reading

- Single or bulk upload: drag and drop, or choose several files.
- Files can be PDF, PNG, JPEG or WebP, up to 10 MB and 30 pages each.
- The file is stored privately, as before. Its SHA-256 fingerprint means uploading the same file again creates nothing
  new.
- Text PDFs are read from their text layer. Scanned pages are read with the bundled OCR. Mixed files use both.
- OCR keeps the word positions and Tesseract's own word confidence.
- Scanned pages that are upside down or sideways are turned upright first. Tegh reads the page at each turn and keeps
  the reading with the best OCR confidence.
- A PDF holding several invoices is split into one invoice each: on "Page 1 of …", on a change of invoice number, or
  on a new header after a total.
- Document type: supplier invoice, supplier credit note, receipt, statement of account, or not an invoice.
- Password-protected PDFs ask for the password in the browser. The password is never sent to Tegh or stored.
- Damaged or cut-off files are refused at upload with the reason.

## What is read

- **Supplier:** name (legal and trading where printed), address, phone, email, and GST/HST, QST and PST numbers.
- **Header:**
  - invoice number, kept exactly as printed (leading zeros too);
  - invoice date, and the due date when printed (a due date worked out from "Net 30" is marked as calculated);
  - payment terms, PO or reference, and currency (marked as assumed when not printed);
  - bill-to, ship-to and shipping details;
  - for credit notes, the original invoice number.
- **Lines:** item code, description, quantity, unit, unit price, discount, amount and tax mark. Freight becomes its own
  line.
- **Totals:** subtotal, discount, freight, each tax separately (GST, HST, PST, QST), whether prices include tax,
  rounding, total, amount paid and balance due.
- Each value keeps what was printed, the page and position (shown on the document with **Source**), and how it was
  read.

## Checks before anything is drafted

- **Supplier:** matched from the name, email domain, phone, postal code and patterns confirmed on earlier invoices.
  Uncertain or conflicting matches are flagged. **Suppliers are never created from a document:** add them in Vendors
  first. The upload area says so.
- **Arithmetic, in exact cents:**
  - quantity × price − discount = line amount;
  - the lines against the subtotal;
  - the tax lines against the tax total;
  - subtotal + tax (± discount, freight, rounding) against the total;
  - total − paid against the balance due.
- **Tegh's own calculation:** Tegh works the invoice out with the chosen tax codes and compares it with the document.
  A rounding difference (Tegh rounds tax per line) is shown and must be accepted explicitly. Nothing is adjusted to
  make figures agree.
- **Dates:** future dates, dates before the books start, a locked period (the draft can be saved; issuing is refused)
  and dates that can be read two ways (both offered, none guessed).
- **Accounts:** only active, non-control expense or asset accounts. Tax codes must be active.
- **Duplicates:**
  - exact: the same supplier, number and total;
  - probable: the same supplier and number, or nearly the same content as another upload;
  - to check: the same supplier and total within 7 days, or another supplier using the same number.
  An owner or admin can override a duplicate with a written reason. The override is kept in the audit trail. The
  vendor invoice service still refuses a number that is already used.

## Suggestions, with their reasons

- **GL account per line:**
  - first, your own earlier coding for the same supplier and wording (learned when a draft is created);
  - then the R152 history matcher;
  - then the supplier's default expense account.
- **Tax code:**
  - the supplier's earlier code;
  - else the one code whose rate gives the printed tax;
  - else the company's home code when tax is printed.
- What is learned stays in that company.

## Confidence and review

- Each field gets High, Medium or Low from stated rules, shown on hover:
  - how it was read (text layer, or OCR word confidence);
  - whether its printed label is beside it;
  - whether a check involves it.
- No percentage is shown except Tesseract's own word confidence.
- Each invoice is **Ready for review**, **Review required** or **Blocked**:
  - Blocked: a duplicate, the document's own figures not adding up, a non-invoice, or a disallowed account or tax code.
  - Even a ready invoice is only drafted, never posted, here.
- Review screen:
  - the document (zoom, pages, source highlight) beside the editable header and line grid, with GL and tax pickers;
  - the checks, duplicates and suggestion reasons, and the correction history;
  - Save, Recalculate, Read again, Cancel, and Create draft.

## Drafts and posting

- **Create draft vendor invoice** uses the existing vendor invoice service. The draft has no journal entry and does not
  change the GL or what you owe suppliers. Tegh then opens the Vendor Invoice & Note Register on the new draft.
- **Supplier credit notes** become draft supplier credit notes through the note service.
- Posting is unchanged:
  - Issue from the register, or the button on the review screen, which calls the same service.
  - Large amounts go to dual-control approval.
  - Locked periods are refused.
- Invoice Intelligence follows the vendor invoice: drafted, approved, posted, or deleted (then it can be drafted again).
- Repeating "Create draft", even twice at once, returns the same draft. A repeated or failed reading never creates a
  second vendor invoice.

## Security and data

- Reading happens in the browser with the bundled runtime. No document is sent to an outside service or AI provider.
- Permissions:

  | Who | Can |
  |---|---|
  | Viewer | See the list |
  | Editor and above | Upload, correct and draft |
  | Owner or admin | Override duplicates |

- Every request is checked against the company.
- The evidence snippets kept for review are encrypted (AES-256-GCM). The key is derived from `app.secret` without
  changing it.
- Audit events record each upload, read, correction, override, retry, cancel and draft, without document text.
- Text inside a document is treated as data. An instruction printed on an invoice is not followed.

## Files

New:
- `api/invoice_intel_r170.php`
- `assets/tegh-invoice-intel-r170.js`
- `assets/tegh-invoice-intel-r170.css`
- `R170-INVOICE-INTELLIGENCE-ARCHITECTURE.md`, `R170-MIGRATION.md`, `R170-OPERATIONS.md`

Changed:
- `api/index.php` (route)
- `api/backup.php`: new tables in backups; a restored copy re-encrypts the evidence for its new identifiers.
- `assets/tegh-native-ocr-v5220.js`: page reader with word positions, confidence and rotation; password and page
  limits; discount, unit and item-code columns.
- `assets/tegh-portal-v5990.js`: menu entry (Full and Guided), route, Tegh Assist questions, and the Original PDF button.
- `assets/tegh-gate-v5990.js` (on-demand module)
- `app.html`, `assets/tegh-preflight-v5990.js`: cache token `5990-r170-intel`.
