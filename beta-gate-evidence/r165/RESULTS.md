# R165 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R165-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `ab1f4e5832aa41ee8546784c065d6592614a048a061c698811a10476d3edc591` |
| Files | 415, all mode 0644 |
| FILE-MANIFEST | 414 entries, all verified after unpacking |
| Base | the R164-1_1 build supplied on 2026-10-22 (commit `b429a3f`) |
| Source commit | `c3b3293`, branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R165 changes:** report row actions now open the record they name (see `tegh-app/R165-CHANGES.md`). Only browser
files changed. The server, database and accounting are unchanged.

**Where it was tested:** a local test host (Apache 2.4, PHP 8.3-FPM, MariaDB, HTTPS with a private CA, clock pinned
to 2026-10-01 Toronto). This is not the IONOS host, and nothing was run against books-test or production. All data
is synthetic.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP: fresh install, every API and browser suite, upgrade (`summary.txt`, `run-all-full-run.log`) | **784 PASS · 7 INFO · 4 FAIL** (failures below) |
| New suite `40-r165` (`r165.json`): View vendor invoice and View note (vendor and customer), totals and balances checked against the database, note number link, original-invoice link, selection bar, note screens, Day Book source, Back button, phone width, no browser errors | 11/11 PASS |
| Row-action sweep: every report's row menu, each item clicked (`r165-row-actions.log`) | 36 actions: 32 recorded OK; 4 are Day Book "Show General Ledger lines", flagged NONE by the detector, but they add the ledger lines (14 → 19/20 rows, checked separately) |
| Selection-bar sweep: one row selected per report, each action clicked (`r165-selection.log`) | 43/43 OK |
| Upgrade from an R118 install with data | 13/13 PASS: balances unchanged, all journals balance |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 390–1440, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

Six reports have no rows in this test company, so neither sweep could exercise them: Audit History (no row actions),
Bank Reconciliation Summary, Tax Code Report, Pay Run Register, Budget versus Actual and Fixed Asset Register.

## The 4 failures come from the supplied base, not from R165

The supplied R164-1_1 build replaced `product.html`, `subscriptions.html`, `terms.html` and `privacy.html` with a new
marketing site. R165 does not touch these files. Four older checks look for content those pages no longer have:

- **PW-01, WR-09:** the "Payroll Support Tool, Canada excluding Quebec" wording.
- **BS-07:** the terms and privacy version shown on the published pages. The new pages are static drafts with no
  version marker; invitations record `2026-10-21-beta.1`.
- **BS-09:** the operator details from config.php and the beta conditions on the Terms page. R156 requires these on
  the published Terms page. **The owner should decide** whether the new draft pages are meant to replace that.

## The earlier run

The first run of this ZIP had 8 more failures. The test host's pinned clock had drifted one day after a container
restart, so dates were a day off. After re-pinning the clock, the second run (the one reported above) passed all 8.
The first run's files are kept on the test host only.

## What each kind of evidence means

- **Source review:** the code was read; nothing executed.
- **Automated tests:** executed on the test host above against the exact ZIP.
- **IONOS host checks:** none performed. Follow `beta-ops/R164-OPERATOR-CHECKLIST.md`, which is unchanged for R165.
