# R170 gate results and beta readiness

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R170-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `7f3db76117c6a5324b867667d56b27078b9e543ff0a7a57f99d71438a7ac7924` |
| Files | 426, all mode 0644; FILE-MANIFEST 425 entries, all verified after unpacking |
| Source commit | `f50a5bb` (`git archive f50a5bb:tegh-app`), branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R170 adds** (see `tegh-app/R170-CHANGES.md`): Invoice Intelligence in Payables (Guided: Money Out › Read
Supplier Bills). Supplier invoice PDFs and images are read in the browser, checked in exact cents, reviewed beside the
document and turned into **draft** vendor invoices or supplier credit notes through the existing services. A draft
posts nothing. Four new tables are created on first use (`tegh-app/R170-MIGRATION.md`).

**Where it was tested:** the local test host (fresh install from the exact ZIP, clock pinned to 2026-10-01 Toronto,
synthetic data only). This is not the IONOS host, and nothing was deployed to staging or production from here.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP: reset, suites 01–39 (`run-all-gate.log`, aggregated) | **805 PASS · 7 INFO · 5 FAIL** (failures below) |
| Suites 40-r165 … 44-r169 in the same run | 45/45 PASS |
| New suite `45-r170` in the same run (`r170-gate.json`) | 56 PASS · 6 INFO (measurements) · 1 FAIL (`II-UI-GUIDED`, a test fault, below) |
| `45-r170` re-run after the first test fix (`r170-rerun.log`) | 56 PASS · 6 INFO · 1 FAIL: `II-UI-GUIDED` now opens the screen, but counted the supplier note before the screen had drawn it; accuracy figures identical; `II-BACKUP` PASS |
| `II-UI-GUIDED` alone after the second test fix, Assist window open first as in the suite (`r170-guided.log`) | 2/2 PASS (the check, and no browser errors) |
| End-to-end journeys, UI journeys (desk and phone) | 53/53 and 22/22 PASS |
| Upgrade from an R118 install with data (`upg-verify.log`) | 13/13 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 320–1920, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

The container did not restart during this run. The gate is now resumable (`tests/gate-resume.sh`), so a restart
would have continued, not repeated, the run.

### Failures

From the supplied R164-1_1 base, unchanged since R165:

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07:** the published terms and privacy version on the pages.
- **BS-09:** the operator details and beta conditions on the Terms page. **The owner should decide** on this one.

Test faults, not product faults:

- **IN-09** (suite `21-r152`, Document Intake reads the next invoice from a known supplier). The check looked at the
  document card while the previous copy of the Intake page was still in place, the same race fixed in `16-r145` in
  R169. Run alone twice afterwards, `21-r152` passed every check both times, IN-09 included. The suite now waits for
  the newly drawn page.
- **II-UI-GUIDED** (new). The test before it opens the Tegh Assist answer window, which (as a dialog should) covers
  the page; the click on the sidebar could not reach the menu. Checked separately: with the window closed, Money Out ›
  Read Supplier Bills opens Invoice Intelligence with the supplier note. The test now closes the window first and
  waits for the screen to finish drawing before looking for the supplier note; run in the suite's order, it passes.

## Invoice Intelligence: what was measured

22 synthetic supplier invoice files (`tests/make-fixtures.py`; fictional suppliers, expected values from the
generator's own integer-cent arithmetic, `tests/expected.json`), uploaded together through the screen and read by the
in-browser worker:

| Measure | Result |
|---|---|
| Header fields (number, dates, totals, tax, currency) | 141/147 (95.9 %) |
| Line items (amount and description) | 73/75 (97.3 %) |
| Supplier matching (known suppliers) | 21/21 (100 %) |
| Review classes after reading | 7 ready · 14 review required · 3 blocked |
| Time from upload to result, per file (queue included) | 7–88 s; the whole batch about 1.5 minutes |

- Every text PDF read completely, including English, French, two-page, credit note, USD, discount and freight cases.
- Scans F04, F05, F20 and F21 read completely by OCR, including an upside-down and a sideways page.
- **F06** (100 dpi, heavy noise) could not be read. All six of its missed header fields and both missed lines are
  F06's. It went to **Review required** with nothing guessed.

Controls checked (all PASS):
- **Duplicates:** the same file again creates nothing; a re-generated copy is blocked; an override needs an owner or
  admin, a written reason, and is audited.
- **Arithmetic:** a document whose figures do not add up is blocked; a one-cent per-line rounding difference must be
  accepted explicitly.
- **Unknown supplier:** never created.
- **Drafts:** a draft has no journal and leaves the AP balance unchanged; repeating the request returns the same
  draft. Issuing posts through the normal workflow, balanced.
- **Locked periods:** issuing into a locked period is refused.
- **Permissions and isolation:** a viewer cannot upload, correct, draft or act as a worker; another company cannot see
  or change the invoices.
- **Jobs:** leases, retries and the three-attempt limit work.
- **Evidence:** the snippets are encrypted, and document text is kept out of the audit trail and the error log.
- **Backup and restore:** a backup carries the four tables; a restored copy keeps its jobs and can read its
  re-encrypted evidence.
- **Instructions inside a document** are not followed.
- **Review screen:** at 1920×1080 and 1366×768, light and dark, side and top navigation, zoom 125 % and 150 %:
  document and invoice side by side, no sideways scroll.

## Beta readiness

**Ready for staging trial on the test-host evidence above, with these conditions:**

1. **Deploy to staging first.** Run the four host checks at the end of `tegh-app/R170-OPERATIONS.md` on the IONOS
   staging host: 10 MB uploads, the OCR runtime files served, one synthetic invoice end to end, and private storage
   not reachable by URL. None of these were done from here.
2. **Production needs your separate authorization.** Nothing here touched production or existing financial records.
3. **Known limits** (`tegh-app/R170-INVOICE-INTELLIGENCE-ARCHITECTURE.md` §5):
   - English OCR data only.
   - Very poor scans are not read.
   - No purchase-order or receiving match, because Tegh has no PO module.
   - Vendor invoice numbers are unique per company, not per supplier.
   - The browser tab must stay open while a batch is read.
4. **Safari** was not available here; the review screen was tested in Chromium.

## What each kind of evidence means

- **Automated tests:** executed on the local test host against the exact ZIP.
- **Source review:** design and controls are described in the R170 documents in the package.
- **IONOS host checks:** none performed.
