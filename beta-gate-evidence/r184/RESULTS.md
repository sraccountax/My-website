# R184 results: Report Builder

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R184-PRIVATE-BETA.zip`
SHA-256 `ce8086463bf7c7f5c8d474d6b67913aede87bc0bca6801a714f45dbcd8fc2d8d`, 435 files, manifest verified.

Where each test ran:
- **Automated tests:** on the test server, with synthetic companies only.
- **Source review:** of the R184 changes.
- **IONOS:** nothing was deployed to or tested on the IONOS server.

## 1. Canadian before/after comparison (switch off, as installed)

The method is the same as for R181 to R183:
- R184 code runs on the R180 data snapshot (62 companies);
- 609 reports and screens are captured;
- the capture is compared with the R180 capture and with the R183 capture.

| Comparison | Result |
|---|---|
| R184 against R180 | Reports all identical. Screens differ only on **Bank Transaction Report**, by R183's Download and R182's Add transaction buttons, as in R183 |
| R184 against R183 | **0 differences** in every file captured in both runs |
| Companies skipped by the full capture | The full screen capture skips the screens of one or two companies per run, and a different one each time. Each skipped company was captured on its own: 04733e… matches R183 exactly (17 of 17). cdb671… matches R180 and R183. 54ef58… is not in the R180 snapshot: both R183 and R184 show the same "Choose a company first" page, which differs only by its random support reference |

With the switch off, the Reports menu, the Professional Reports list and every existing report are unchanged.

Files:
- `comparison/run-compare-r184.log`
- `comparison/r184-off-report.json` (against R180)
- `comparison/r184-vs-r183-report.json`
- `tests/run-compare-r183.sh`

## 2. Full gate on this exact ZIP

Fresh install from the ZIP, every suite once, then the tail.

| Part | Result |
|---|---|
| All suites (53 result files) | **1061 PASS**, 13 INFO, 6 FAIL. R183 had 1045 PASS; the 16 new passes are suite 58-r184. The six failures are the same known ones as R177–R183 |
| R184 suite `58-r184` | **16/16 PASS** |
| R183 `57-r183`, R182 `56-r182`, Guided, R163 | 5/5, 23/23, 23/23, 46/46 PASS |
| R174 site settings, R177 dashboard | 21/21, 24/24 PASS |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |

Known failures, unchanged:
- BS-07 and BS-09: policy pages not published on the reset host.
- PW-01 and WR-09: public-page wording checks, failing since R175.
- DI-SCAN: environment check of the old R144 reader.
- DD-01: top-bar click timing on this host.

Logs: `logs/run-all.log`, `logs/tail-run.log`, `logs/r184.json`.

## 3. What 58-r184 checks

Every expected figure is worked out in the test from the records it enters, not read from Tegh.

**Test data:** a fresh Ontario (HST 13%) company with:
- **Invoices:**
  - Jul 10, Maple, 10 × 100.00 = 1,130.00
  - Aug 15, Maple, 2 × 300.00 = 678.00
  - Aug 20, Birch, 3 × 150.00 = 508.50, paid Sep 1
  - Sep 5, Birch, 2,000.00 = 2,260.00
- **Bills:** Jul 20, 565.00 and Sep 12, 226.00
- **Journal:** one entry of 25.00
- **Bank CSV:** +508.50 and −25.00

| Check | What is verified |
|---|---|
| RB-00 | With the switch off, every route refuses and no table is created |
| RB-01 | Invoices by customer with months side by side: each cell, row total and the grand total of 4,576.50 |
| RB-02 | Change and % change columns; open balance after the payment |
| RB-03 | General Ledger debits and credits by account equal the Trial Balance for the same period, for every account |
| RB-04 | Sales tax lines: 526.50 collected, 91.00 paid |
| RB-05 | List mode with filters and sort; bank money in and out |
| RB-06 | Subtotals with two row fields; "is one of" and "contains" filters; quarter and financial-year columns |
| RB-07 | Refused: unknown fields, SQL text in field names, unknown settings, too many columns, rows or filters |
| RB-08 | Save; the same save twice saves once; a different save with the same key is refused (409); a saved report runs with a new period; audit entries; the books are unchanged |
| RB-09 | Edit by the creator; an edit from an older screen is refused (409) |
| RB-10 | Sharing and roles in a second company: viewer, admin and another owner. Private reports are hidden, shared ones run with the reader's own permissions, and only the creator can edit |
| RB-11 | Pick-list values come only from the company in use; each run is audited |
| RB-12 | Browser, end to end: build, run, open the lines behind a figure, Excel/CSV/PDF downloads, save, reopen, delete, and a wide report as PDF |
| RB-13 | Phone width without sideways scrolling; menu shown or hidden by the switch and in Guided |
| RB-14 | The Site & Release Settings card saves the switch |
| RB-15 | Company backup and restore keep the saved report, which gives the same total (4,576.50) afterwards |

## 4. Not covered

- **The IONOS deployment:** not deployed and not tested there.
- **The database table:** it is created the first time a report is saved, which needs the CREATE privilege on IONOS, as R174 and R182 did.
- **Large real books:** checked only against the limits (60 columns, 5,000 summary rows, 25,000 list lines). Response times on large books were not measured.
- **Not built:** payroll, budgets, fixed assets, products, reconciliations and audit-log data areas, calculated columns and templates (planned for R185), and scheduled email (planned for R186).
