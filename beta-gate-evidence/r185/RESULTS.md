# R185 Speed: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R185-PRIVATE-BETA.zip`
- SHA-256: `d1b68eefafc6231b2f8c438e37bfe51e3e3ce55f8c22b6e7dd736bc09d8aebb1`
- 433 files; FILE-MANIFEST: all 432 entries OK.
- 26.6 MB (R184: 26.9 MB).

Everything here was run on the test server (gate.test). **Nothing was run on the IONOS server.**

## 1. Timings (automated browser measurements on the test server)

Setup:
- synthetic company "Perf Volume": 549 invoices, 153 bills, 981 journal entries, 600 bank lines;
- network throttled to 20 Mbit/s with 60 ms latency;
- 3 runs per release; the figure shown is the median.

Raw results: `perf/before-r184-*.json`, `perf/after-r185-*.json`, `perf/compare-r185.json`.

| Step | R184 | R185 | Change |
|---|---|---|---|
| Sign in to dashboard | 3175 ms | 2264 ms | −29% |
| Reload, signed in | 2820 ms | 1714 ms | −39% |
| Customer Invoice & Note Register | 2944 ms | 972 ms | −67% |
| Vendor Invoice & Note Register | 1534 ms | 472 ms | −69% |
| Customers | 560 ms | 349 ms | −38% |
| Vendors | 426 ms | 230 ms | −46% |
| Receivable Ageing | 582 ms | 440 ms | −24% |
| Bank Transaction Report | 567 ms | 377 ms | −34% |
| Day Book | 1268 ms | 1116 ms | −12% |
| All 19 measured steps together | 17.8 s | 11.8 s | −34% |

Notes:
- **Sign-in check:** `startup/prepare` server time fell from about 360 ms to about 120 ms.
- **Overlapping ranges:** Trial Balance, Balance Sheet and Match and Post overlap between releases, so no change is claimed for them.

## 2. Full gate (automated, fresh install from the R185 ZIP)

| Result | Count |
|---|---|
| PASS | 1073 |
| INFO | 13 |
| FAIL | 6 |

The 6 failures are the same known ones as R184: BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

Other checks:
- Path and header checks: 45 PASS, 0 FAIL.
- UI runs: 20, with no screens showing issues.
- Swipe traps at 390×844: 0.

Logs: `logs/run-all.log`, `logs/tail-run.log`, `logs/r185.json`.

## 3. Suite 59-r185 (automated, inside the gate)

| ID | Check | Result |
|---|---|---|
| PF-01 | One release token across app.html, .htaccess, the gate and preflight | PASS |
| PF-02 | Release-token files are cached for a year and immutable, fonts for 30 days; other tokens are not | PASS |
| PF-03 | The new foreign-key reading matches the old joined query row for row | PASS |
| PF-04 | Startup status: all 14 checks ready, not degraded | PASS |
| PF-05 | At least 14 interface files are preloaded when the sign-in form is focused | PASS |
| PF-06 | Sign-in reads the company data once, alongside the check, with no page errors | PASS |
| PF-07 | The old register table is already hidden when the page is ready; the sealed register shows 25 of 40 records | PASS |
| PF-08 | Row actions menu items are unchanged | PASS |
| PF-09 | The register total equals the hand-computed total of the 40 invoices | PASS |
| PF-10 | Profile menu panel gets its class, is at most 400 px wide and scrolls | PASS |
| PF-11 | No page errors | PASS |
| PF-12 | Removed stylesheets are absent from FILE-MANIFEST and referenced nowhere | PASS |

## 4. Canadian before/after comparison (automated, test server)

Method:
- restored the R180 database snapshot (68 companies);
- captured the reports and screens with the R185 code;
- compared them with the R180 capture and the R184 capture.

**Against R180**
- 592 files compared; 0 status changes.
- 2 files differ: the Bank Transaction Report for two companies. This is the R183 change (running balance, date order), and R184 showed the same 2 differences.

**Against R184:** 592 files compared, **0 different**.

**Companies the full capture skipped**

The capture tool sometimes skips companies. This time it skipped two, so each was captured on its own:

| Company | Result |
|---|---|
| 04733e… | All 17 screens are identical to R184. Against R180, only the Bank Transaction Report differs (the same R183 change). |
| cdb671… | All 17 screens match R184 except the "Support reference" line. These pages say "Choose a company first" because the company is not in the snapshot, and each load gets a random reference. |

Files: `comparison/`.

## 5. Not covered

- **IONOS:**
  - no deployment or timing there;
  - its database version may differ, so sign-in timings will differ.
- **Real browsers and networks:** the timings come from headless Chromium with simulated throttling, not from people's real browsers and networks.
- **Browser cache:** the one-year cache depends on Apache honouring `.htaccess` (mod_headers and mod_rewrite). This was checked on the test server only.
