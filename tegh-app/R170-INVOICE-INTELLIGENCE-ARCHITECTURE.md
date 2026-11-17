# R170 — Invoice Intelligence: architecture and integration findings

This document records what the existing Tegh code already does (Phase 1), the decisions taken for R170, and the
limits that remain. The source of truth is the code in this package. Statements about the IONOS host are marked as
unverified wherever they were not checked on that host.

## 1. What already exists (inspected in the code)

| Area | Where | What it does today | Works? |
|---|---|---|---|
| PDF text reading | `assets/tegh-native-ocr-v5220.js`, bundled pdf.js under `assets/tegh-ocr/v5220/pdfjs` | Reads the text layer of a PDF in the browser, with positions | Yes. Used by Document Intake and the bank statement reader; covered by suites `21-r152` and `31-r163`. |
| OCR | Same file; bundled Tesseract.js 5 (WebAssembly) with English data under `assets/tegh-ocr/v5220` | Recognises scanned pages and photos in the browser. The runtime is checked against a pinned SHA-256 manifest before it runs. | Yes, for English text. No French language data is bundled. Before R170 it returned text only (no word positions or word confidence) and up to 8 pages. |
| Invoice field reader | `candidateFromText`, `lineItemsFromRows` in the same file | Supplier, number, dates, terms, subtotal, tax lines (GST/HST/PST/QST/TPS/TVQ), total, amount due, currency, line table | Yes, for one invoice per file. Its `confidenceBps` was a count of fields found, not a measured score. |
| Document Intake | `api/native_ap_ar_v5600.php` (`native-ap-ar/document/*`), `api/intake_r152.php` | Private upload (PDF, PNG, JPEG, WebP; 10 MB), SHA-256 of the file, re-upload of the same file returns the same record, review states, supplier memory, coding suggestions from history, duplicate warnings by number and by total within 7 days | Yes. |
| Private storage | `save_private_upload()` in `api/imports.php` | Files outside the web root, random names, mode 0600, MIME checked with `finfo`, path traversal refused | Yes. Files are not encrypted by the application. |
| Vendor invoices (bills) | `create_bill_record()` and `handle_bills()` in `api/books.php`; itemized lines in `api/documents_r151.php` | A **draft** bill creates no journal and changes no GL or AP balance. **Issue** checks the date is not in the future, is on or after the books start, and is in an open period; above the materiality threshold it goes to dual-control approval (preparer cannot approve; approver posts). Per-line GL account (non-control expense or asset only) and tax code. | Yes. |
| Supplier credit notes | `r151_note_create_unlinked()` | Draft vendor credit note with lines, needs the original invoice number; posts through the normal note workflow | Yes. |
| Tax engine | `api/tax_codes_r137.php`, `r151_compute_lines()` | Tax codes with components, recoverable or cost, tax-inclusive or exclusive, multi-currency with exact cent allocation | Yes. |
| Permissions | `company_role_permissions()` in `api/bootstrap.php` | viewer, editor, admin, owner, support. `bills.write` (editor and up), `bills.reverse` (admin and owner), `attachments.write` | Yes. |
| Audit trail | `audit_event()` | Append-only company audit events | Yes. |
| Backups | `api/backup.php` | Includes `native_agent_documents`, `vendor_document_memory` and the private files | Yes. |
| Tegh Assist | `api/command_centre_v5600.php`, `api/ai_router.php`, free-text handler in `assets/tegh-portal-v5990.js` | Deterministic command routing; no external model is called (`tegh_connected_release_enabled()` is false) | Yes. |
| Background jobs | `api/native-agent-cron.php`, `api/monitor-cron.php` | Run from cron where the host has it | The operator checklist assumes IONOS cron and SSH. **Not verified on IONOS from here.** |

## 2. IONOS runtime (Phase 1, item 4)

Not verified. This session has no access to the IONOS account, and testing against the IONOS deployment was ruled
out. What the package itself sets: `api/php.ini` has `upload_max_filesize = 10M`, `max_execution_time = 120`,
`memory_limit = 256M`. Whether IONOS honours that file, allows `exec()`, has the `tesseract` or `pdftoppm` binaries,
or runs cron for this account must be checked by the operator (see `R170-OPERATIONS.md`).

## 3. Decisions for R170

1. **OCR runs in the browser, with server-side job records.** The bundled pdf.js and Tesseract.js already run without
   any server binary, any external service or any per-document charge. On shared hosting, PHP cannot be assumed to
   run OCR binaries or long requests. The browser tab that opens Invoice Intelligence is therefore the worker:
   - It claims a job from the server with a lease, processes it and submits the result.
   - If the tab closes, the lease expires and another tab can claim the job. A job is retried at most 3 times.
   - The server never parses PDF content beyond checking the file signature, the end-of-file marker and the encryption
     marker. A broken PDF can therefore only affect the browser tab reading it.
2. **Modular OCR adapters.** The reader calls an adapter interface with two methods: `textLayer(page)` and
   `recognise(canvas)`. The pdf.js and Tesseract adapters are in `assets/tegh-native-ocr-v5220.js`. A future
   server-side or separate-worker adapter can submit the same result format to the same endpoint
   (`invoice-intel/job/submit`).
3. **Reuse, not duplication.** Files go through the existing private upload and `native_agent_documents` record, so
   the file fingerprint, private storage and backup behaviour are unchanged. Drafts go through `create_bill_record()`
   (never `issue`) or `r151_note_create_unlinked()`. Posting stays in the vendor invoice register's normal Issue and
   approval workflow.
4. **New tables** (created on first use; see `R170-MIGRATION.md`):
   - `invoice_intel_jobs`: one per invoice found in a file. It holds the state, lease, attempts, the extraction
     (raw and normalized values), confidence, validation results and the created draft.
   - `invoice_intel_corrections`: every field a person changed, with the old and new value.
   - `invoice_intel_overrides`: authorised duplicate overrides, with the reason.
5. **Evidence is encrypted.** The document text snippets stored as evidence for each field are encrypted with
   AES-256-GCM. The key is derived from `app.secret` with HKDF and its own label, so no existing secret is rotated or
   reused directly. Ordinary logs never receive document content.
6. **No invented confidence.** Each field gets High, Medium or Low from stated rules: the source quality (text layer,
   or Tesseract's own word confidence), whether a printed label anchors the value, and whether the document's
   arithmetic agrees. The reasons are shown next to the level. No percentage is shown except Tesseract's word
   confidence, which is a real measurement.

## 4. Integration points

| Step | Existing service used |
|---|---|
| Store file and fingerprint | `save_private_upload()`, `native_agent_documents` (unique per company + SHA-256) |
| Supplier match | `vendors` (name, business number, email), `r152_memory_lookup()`, `native_vendor_fingerprints()` |
| GL and tax suggestions | `r152_coding_suggestions()` (lines from the vendor's approved bills), vendor default account, `tax_code_home()` |
| Duplicate checks | `bills` (vendor, number, total, date), `native_agent_documents` (file hash), R170 content fingerprint |
| Tax arithmetic | `tax_code_get()`, `tax_code_compute()` |
| Period lock | `assert_period_open()` at draft time (warning) and at issue (enforced by the existing service) |
| Draft vendor invoice | `create_bill_record($user,$company,$input,'invoice_intelligence')`, no `issue` flag |
| Draft supplier credit note | `r151_note_create_unlinked()` |
| Posting | Unchanged: Vendor Invoice & Note Register, Issue; dual-control approval above materiality |
| Audit | `audit_event()` for upload, extraction, each correction, override, draft creation, cancel and retry |

## 5. Known limits

- English OCR data only. French invoices with a text layer read normally; scanned French invoices are recognised
  with English data, so accented words are less reliable.
- Purchase orders: Tegh has no purchase-order module, so PO matching and over-billing checks are not possible. The
  PO number is extracted and stored as a reference only.
- Inventory: bills post to expense or asset accounts through the existing service. There is no perpetual inventory
  receipt to match.
- Rotation is detected for scanned pages by trying 0°, 90°, 180° and 270° and keeping the angle with the best
  Tesseract confidence. A small tilt is measured from the text baselines and corrected before columns are read.
- Very poor scans are not read reliably. In the synthetic set, a 100 dpi scan with heavy noise (F06) could not be read
  at all; it goes to **Review required** with the reason, and nothing is guessed.
- Vendor invoice numbers are unique per company in the existing vendor invoice service, not per supplier. Two
  suppliers using the same number cannot both be drafted with that number; the second draft is refused with the
  service's own message and the number must be changed by the reviewer (for example with a suffix).
- The browser must stay open while a batch is read. Jobs left half-done resume when Invoice Intelligence is opened
  again.
