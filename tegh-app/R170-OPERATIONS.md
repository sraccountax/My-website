# R170 — operating Invoice Intelligence

## How the reading work runs

- Files are read in the browser of the person using Payables › Invoice Intelligence. The bundled pdf.js and
  Tesseract.js runtime does the reading. It is checked against a pinned SHA-256 manifest and never calls an outside
  service. The server needs no OCR binaries, no `exec()`, no cron job and no long-running PHP request.
- Each file is a job. A browser tab claims one job at a time with a 3-minute lease and renews it every minute while it
  reads. A lease can be renewed for up to 20 minutes in total.
- If the tab is closed, or the computer sleeps, the lease runs out. The next time anyone in the company opens
  Invoice Intelligence, the job is claimed again.
- A job is attempted at most 3 times. After that it stops as "Could not be read" with the reason "retry_limit". A
  person can choose Retry.
- At most 4 jobs per company are read at the same time, across all open tabs.

## Monitoring

| What to watch | Where |
|---|---|
| Invoices waiting or being read | Invoice Intelligence: the "Reading" and "Could not be read" filters |
| Jobs stuck in Reading | `SELECT id,company_id,attempts,lease_expires_at FROM invoice_intel_jobs WHERE state='processing' AND lease_expires_at < UTC_TIMESTAMP();` These are released automatically on the next claim or list. |
| Failed reads by reason | `SELECT failure_code,COUNT(*) FROM invoice_intel_jobs WHERE state='failed' GROUP BY failure_code;` |
| Who did what | Audit trail events `invoice_intel.uploaded`, `.read`, `.read_failed`, `.corrected`, `.duplicate_overridden`, `.retry`, `.cancelled`, `.draft_created`, `.duplicate_file` |

Audit events and logs record field names, counts, states and IDs. They never record document text.

## Recovery

- **A file failed to read:** check the reason on the row.
  - Password: open the file again and enter the password. It stays in the browser.
  - Damaged or cut off: upload a good copy.
  - More than 30 pages: split the file.
  - Otherwise choose Retry.
- **A wrong draft was created:** delete the draft in the Vendor Invoice & Note Register, as for any draft. Invoice
  Intelligence notices the deletion and lets the invoice be reviewed and drafted again. A posted invoice is
  corrected with the normal void or reversal workflow.
- **The same file was uploaded twice:** the second upload is recognised by its SHA-256 fingerprint and creates no new
  job.

## Backup impact

- Company backups include the four new tables and, as before, the original files (`native-documents` in private
  storage).
- Evidence snippets stay encrypted inside the backup. Restoring as a new company on the same host re-encrypts them
  for the copy's new identifiers, so the copy can still show them. Restoring onto a host with a different
  `app.secret` keeps everything except the snippets.
- Storage: one original file per upload (up to 10 MB), plus about 5–50 KB of database rows per invoice.

## Retention

The original file stays attached to its vendor invoice. It follows the existing private-document retention: it is
removed only with the company, through the documented deletion request. Cancelled invoices keep their file and history
until then.

## Checks on the IONOS host before beta use (not done from here)

1. Uploads of 10 MB pass (`upload_max_filesize` and `post_max_size` in the host's PHP settings).
2. `https://<host>/assets/tegh-ocr/v5220/tesseract/tesseract-core-lstm.wasm` is served with status 200 and type
   `application/wasm` (or `application/octet-stream`).
3. Open Payables › Invoice Intelligence and upload one synthetic invoice. It reaches "Ready for review" or "Review
   required" within about a minute.
4. The private storage folder is outside the web root and not reachable by URL. Existing check:
   `beta-ops/R164-OPERATOR-CHECKLIST.md`.
