# Tegh private beta: readiness evidence

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R155-PRIVATE-BETA.zip`
**SHA-256:** `e7e998dd439c73570d97386dc8a90468367116c21d7920bb70b91fe56242703c`
**Status:** private beta candidate (invitation-only, sample data only). `productionReady: false`, `acceptanceComplete: false`. Neither was changed.
**Full gate on this exact ZIP:** **575 PASS / 0 FAIL / 7 INFO**: all suites, the R118→R155 upgrade (13/13), 42 path and header checks, 20 layout runs with 0 screens with issues, and 34 public pages
**Evidence:** `beta-gate-evidence/r155/`; test scripts in `beta-gate-evidence/scripts/`; the QA report's R155 addendum.

What the build-and-test side (Claude Code) did is marked **Done**. Items only you, your host or an independent reviewer can close are marked **Open**. Every result below comes from the gate's test host, with synthetic data and its own database. None of it covers your beta host until the same checks are run there.

## Your pre-invitation checklist

| Required before invitations | Evidence | Status |
|---|---|---|
| **Company isolation and permissions pass** | **Records:** another company's invoices, PDFs, payments, tax codes, bank lines, credit notes, backups, GL ledgers, customers and viewing links cannot be reached by changing an ID or header (SI-01…13). **Files:** another company's uploaded files, Document Intake and invoice attachments, cannot be downloaded through another company or by a user without access (24-beta BS-02, BS-03). **Roles:** viewer, editor and admin limits hold (RO-01…12). **Members:** a removed member loses access at once, including the session they are signed in with (BS-04). **Requests:** CSRF checks, foreign-origin refusal and upload checks pass (.php, .svg, disguised PHP and oversize files are refused; UP-01…07). | **Done** on the test host. **Open:** independent security review before real client data. |
| **A backup has been successfully restored** | **In the app:** a company backup restores into a new company with an equal trial balance, record counts, ageing and tax codes (BR-01…09), and the uploaded files come back byte for byte (BR-10). A damaged backup is refused (BR-08). **Host level:** `beta-ops/tegh-backup.sh` and `tegh-restore-verify.sh` restored the database and upload folder into a **separate installation**. All files, all 167 tables and every company's posted totals matched, and the restored site showed identical trial balances for every company. A damaged backup was refused. | **Done** on the test host. **Open:** your host administrator schedules daily backups with an off-site copy and runs one restore test on the beta host (`beta-ops/RECOVERY-AND-ROLLBACK.md`). |
| **Core accounting figures reconcile** | **Hand-worked figures:** 63 accounting-control checks (opening balances, period locks, AR/AP control equal to subledgers, reversals) and tax regression, with expected figures worked out independently. **Journeys:** 53 click-through journey checks (invoices, notes, receipts, vendor payments, bank import and matching, reconciliation to the statement, Trial Balance difference $0.00, payroll). **Balance:** every posted entry on the test database balances, and each company nets to zero (BA-02). **Repeat protection:** a repeated payment request records once (BA-01), a double-click on Save & Issue creates one invoice (BA-03), and re-uploading a statement marks every row Duplicate and blocks the import (W9-03/04). **Currency:** foreign-currency invoices are added at their CAD amount (BM-01). | **Done** on the test host. **Open:** your own walkthrough as the accountant with your known sample amounts (step 7). |
| **Invitations, reset emails and sign-out work** | Invitation emails for each role are delivered over STARTTLS with a one-time code, and the code cannot be reused (H-MAIL, R149 IC-*). A revoked invitation cannot be accepted (BS-05). Password reset works end to end: the link works once, the old password stops working and other sessions end (BS-06). After sign-out the old session is refused (SE-01). Public sign-up is closed (BS-01). | **Done** with the test mail sandbox. **Open:** send a real invitation and a password reset to an **external** mailbox from the beta host (SPF/DKIM/DMARC). |
| **Privacy/beta terms accurately describe the service** | `beta-kit/DATA-INVENTORY.md` lists what Tegh stores, what leaves the server, who can see what, and what export and deletion exist. Acceptance is now recorded with the published version, 2026-10-01 (defect DEF-17 fixed, BS-07). | **Open:** you write the notice and terms; legal review before real client data. |
| **No unresolved defect causes data loss, duplicate posting, wrong totals or unauthorized access** | All earlier findings (DEF-01…16, OBS-1…4) were re-checked against their regression tests on R154/R155 and still pass. DEF-17 (wrong terms version recorded) is fixed in R155. No defect in these categories is open. | **Done.** Re-check after any change. |

## The other steps

| Step | What was done | Still open |
|---|---|---|
| 1 Beta rules | The app supports them: invitation-only (sign-up closed), and a **Private beta · sample data only** badge in the top bar with the full note "use sample data only… beta data may be reset". | Your decision on dates and testers. |
| 2 Release package | **Hashes:** FILE-MANIFEST verified, 361 files, no ZIP entries with unusual permissions. **Stale references fixed:** README said the manifests identify "R151", and manifest history entries were off by one; both corrected. **Earlier findings** re-checked (see above). **One clearly named package:** `…-R155-PRIVATE-BETA.zip`. | — |
| 3 Hosting | Confirmed in the package: HTTPS security headers (HSTS, CSP, frame and type options); config, release files, storage, sample data and private config are refused over HTTP (42 path and header checks); no PHP version or stack traces in errors (EL-01…03); `display_errors` off; public sign-up off by default. | Separate beta address and database, HTTPS certificate, `config.php` with `beta_notice`, file permissions on the host. |
| 4 Security check | See the isolation row above, plus client viewing links: code sign-in, unshared reports refused, guessing limits, expiry, and **revocation ends sessions** (CV-01…16). | Independent security review. |
| 5 Backup and recovery | Scripts, restore test and rollback runbook in `beta-ops/`. | Scheduling and one restore on the beta host. |
| 6 Privacy and beta terms | Data inventory drafted. **Gap to decide:** a person's user account can be deactivated but not deleted in the app. | Your text, contact details and legal review; a deletion procedure. |
| 7 Accounting tests | See above. | Your accountant walkthrough. |
| 8 Newest features | The R151–R154 suites pass on R155: invoice lines, OCR corrections and supplier memory, 13 statement layouts plus multi-statement PDFs, multi-company reports, budgets and GL reports, exports and currency. Layout passes at 20 viewport, theme and zoom combinations, including the top-menu layout, phones at 320–390 px and dark mode, with 0 screens with issues and 0 swipe traps. | A real iPhone/Safari check (WebKit is not available on the test host). |
| 9 Payroll limits | No SIN is stored anywhere, in the database or backups (PY-02…05). The SIN-submission check (PY-01) is recorded as INFO, not PASS: a submitted SIN was refused or discarded, and PY-03…05 confirm none is stored. "Canada outside Quebec" shows in the app and on the product page (PW-01/02, WR-08). The review checkbox and reference are required before verifying a pay run (W16-04). Pay figures match hand calculations (W16-01…07). | Use fictional employees; keep your own payroll process. |
| 10 Tester support | `beta-kit/GETTING-STARTED.md`, `FEEDBACK-FORM.md`, `feedback-log.csv`, `sample-statement.csv`. | Fill in the addresses and links; test with an external mailbox. |
| 11 First two testers | — | Yours. |

## Before real client or employee information
1. Independent security review.
2. Privacy and terms legal review, and a user-deletion procedure.
3. Host acceptance in `DEPLOYMENT-NOTES.txt`:
   - PHP lint on the host;
   - `sha256sum -c` on the host;
   - real statement files;
   - restore of a production backup;
   - live postings;
   - the signed-in client-view check;
   - owner review.
4. Real iPhone/Safari check.
