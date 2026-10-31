# R172 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R172-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `e0da5ad581bed5f414e2fb98cb4e30d1ae22e1167358002b24565df2f437bcaf` |
| Files | 431, all mode 0644; FILE-MANIFEST 430 entries, all verified after unpacking |
| Source commit | `88cfcde` (`git archive 88cfcde:tegh-app`), branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R172 adds** (see `tegh-app/R172-CHANGES.md`): ten fixes found by a full functional and screen test of R171.
One of them stops a loss of data: opening the GIFI report replaced GIFI codes a person had chosen. How each problem
was found is in `PROGRESS.md`. There is no database change.

**Where it was tested:** the local test host, fresh install from the exact ZIP, clock pinned to 2026-10-01 Toronto
(`clock-pin.txt`), synthetic data only. This is not the IONOS host.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP: reset, suites 01–39 and 40–47 (`run-all-gate.log`, aggregated) | **805 PASS · 7 INFO · 5 FAIL**: the 4 known base-page items and IN-09, below |
| New suite `47-r172` (the ten fixes, on a fresh company) in the same run (`r172.json`) | **16/16 PASS** |
| `46-r171` (company feed) in the same run (`r171.json`) | 29/29 PASS |
| `45-r170` (Invoice Intelligence) in the same run (`r170.json`) | 57 PASS · 6 INFO · 0 FAIL |
| Suites 40-r165 … 44-r169 in the same run | all PASS |
| End-to-end journeys; UI journeys (desk and phone) | 53/53; 22/22 PASS |
| Upgrade from an R118 install with data (`upg-verify.log`) | 13/13 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 320–1920, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

### Failures

From the supplied R164-1_1 base, unchanged since R165:

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07:** the published terms and privacy version on the pages.
- **BS-09:** the operator details and beta conditions on the Terms page. **The owner should decide** on this one.

Not reproduced:

- **IN-09** (suite `21-r152`: the invoice reader applies supplier memory to a second invoice). In the gate run, the
  uploaded document's card did not appear on the intake screen within 30 seconds, so the check did not run.
  - Suite `21-r152` was then run again on the same install: **31/31 PASS**, IN-09 included (`r152-rerun.log`).
  - IN-09 passed in the R170 and R171 gates.
  - R172 changes nothing in Document Intake or the invoice reader.
  - The cause of the one slow appearance is not established. If it recurs, it should be investigated rather than
    treated as a flake.

## Before this gate: the testing round (`PROGRESS.md`)

**Deep screen sweep** (`tests/deep172.mjs`): 99–115 screens per configuration, each opened, its tabs and filters used
and its forms opened. Twelve configurations:
- desktop at 1024, 1440 and 1920 wide, and phone at 390;
- light and dark, side and top navigation;
- Full Accounting and Guided;
- owner, editor and viewer;
- a company with no data.

The sweep checked each screen for browser errors, failed requests, sideways scroll, controls off screen or covered,
clipped buttons, and fields too narrow to use.

**Export sweep** (`tests/export172.mjs`): every report and every format it offers. 70 downloads across 28 reports,
each the right format and not empty.
- Three CSVs hold only headings because the company has no products or reconciliations; this was checked in the
  database.
- Three screens have no export.

**Journeys driven on screen:**
- **Fixed asset:** $12,000 over 60 months is $200 a month. Posting makes one balanced entry, Dr 6810 / Cr 1590.
  Posting in a closed period is refused.
- **Recurring invoice:** issued with BC GST and PST, $500 + $60, next date November 1.
- **Budget:** planned against actual, matching the general ledger.
- **Customer import:** eligible rows, errors and duplicates separated; only the eligible rows were created.

**Role findings:** in the viewer and editor sweeps, Opening Balances, Client Viewing Links and Pay Run Register were
refused (403, or "not available"). The sweep opened these screens directly; the menus do not offer them to those
roles. These are the intended permission checks, not faults.

## What each kind of evidence means

- **Automated tests:** executed on the local test host against the exact ZIP.
- **IONOS host checks:** none performed. Before beta use, deploy to staging and run the host checks in
  `tegh-app/R170-OPERATIONS.md` and `beta-ops/R164-OPERATOR-CHECKLIST.md`.
- **Production:** not touched; it needs your separate authorization.
- **Browsers:** Chromium only. Safari was not available here.
