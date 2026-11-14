# R183 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R183-PRIVATE-BETA.zip`
SHA-256 `7ef3ae7835f71348cf8f0fa5bb0414fe9de058298589b13499bc5c77a3ed6d0f`, 431 files (430 in FILE-MANIFEST.sha256, all
verified on install).

Where the checks ran: the isolated gate host (`gate.test`) with synthetic data only. **Nothing was run against IONOS,
books-test.sraccountax.ca or production.** These results do not establish how R183 behaves on the IONOS deployment.

## 1. Canadian before/after comparison

Method as in R181 and R182:
- the R180 data snapshot (62 companies);
- 609 captured reports and screens;
- compared with the R180 capture.

| Result | Detail |
|---|---|
| Reports | All identical |
| Screens | Identical except **Bank Transaction Report** on the companies that show it. The differences are the new **Download ▾** button (R183) and **+ Add transaction** (R182 D), both intended |
| Company 04733e… | Its 34 screens were not captured in the full run, as in R182. A capture of that company alone gave 17 screens: 16 identical, and Bank Transaction Report differing only by the same buttons |

The **Balance** column does not appear in these snapshot screens. On this snapshot, the complete report cannot be
prepared because of a test-host clock artifact (the audit clock is pinned earlier than the snapshot's last audit
entry), so the screen shows the on-screen list. Suite `57-r183` checks Balance on fresh companies.

Files: `comparison/run-compare-r183.log`, `comparison/r183-off-report.json`, `tests/run-compare-r183.sh`.

## 2. Full gate on this exact ZIP

Fresh install from the ZIP, every suite once, then the tail.

| Part | Result |
|---|---|
| All suites (52 result files) | **1045 PASS**, 13 INFO, 6 FAIL. The six are the same known failures as R177–R182. R182 had 1040 PASS; the 5 new passes are suite 57-r183 |
| R183 suite `57-r183` | **5/5 PASS** |
| R182 `56-r182`, Guided `30-guided`, R163 `31-r163` | 23/23, 23/23, 46/46 PASS |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |

Known failures, unchanged:
- BS-07 and BS-09: policy pages not published on the reset host.
- PW-01 and WR-09: public-page wording checks, failing since R175.
- DI-SCAN: environment check of the old R144 reader.
- DD-01: top-bar click timing on this host.

Logs: `logs/run-all.log`, `logs/tail-run.log`, `logs/r183.json`.

## 3. Found by the first gate run, and fixed

The first full gate (on an earlier R183 build, not shipped) had three more failures.

| Check | Cause | Fix |
|---|---|---|
| G-09 (Guided) | **An R183 regression.** To put ✎ on the first line, R183 wrapped the description and the pencil in a new span. The first span in the description cell then held "description✎", and code reading that cell no longer found the plain description | The description is again the first span, as before R183. A CSS grid now puts ✎ beside it on the first line, with reference and tag below |
| R-47, R-48 (R163) | **A test race, not R183.** The test set its new company in browser storage and reloaded. The old page's cached session check could restore the first company in between, so Guided showed "Alpine Ledger Partners". The same pair failed once before R177 | R-47 now confirms its own company has loaded before it checks Guided. R-47 also compares the button text without regard to case: the app title-cases button labels after drawing them (since before R183), so the test's reading depended on timing |

After the fixes, the manifests and ZIP were rebuilt. The full gate and the comparison above were run again on this ZIP.

## 4. What 57-r183 checks

Expected balances are worked out in the test from the lines it enters, not read from Tegh.

**Test data:** a fresh Ontario company with a CSV statement (Sep 3 +1,500.00, Sep 10 −120.00, Sep 20 −15.00) and two
lines entered by hand afterwards (Sep 5 +250.00, Sep 15 −800.00).

| Check | What is verified |
|---|---|
| BT-01 | Report lines are in date order, with the hand-entered lines among the statement lines. Balances are 1,500.00 / 1,750.00 / 1,630.00 / 830.00 / 815.00 |
| BT-02 | A search for "hand" shows the same balances on the two matching lines. Starting the period on 12 Sep carries the earlier lines |
| BT-03 | Browser: Balance column shown; Download beside Generate Report gives Excel, CSV (with Balance and 815.00) and PDF |
| BT-04 | Browser, with a simulated server error: Download explains it, shows the support reference, and gives the lines on screen as CSV |
| BT-05 | Browser, Match and Post: date order by default; the amount header sorts up then down; Date returns to date order; + Add transaction is beside Post/Match and not in the filter; ✎ is on the description's first line in every row |

## 5. Not covered

- The IONOS deployment: not deployed and not tested there.
- The "hand-entered lines appear last" report could not be reproduced on the test server: the report was already
  ordered by date there. R183 makes the order fully defined (date, statement order, entry time) and adds sorting.
  If lines still appear last on IONOS, the company, account and dates would help find the cause.
