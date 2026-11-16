# R190 India edition: GST, GST returns, payroll, TDS/TCS, India Home (IN-3a to IN-6): results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R190-PRIVATE-BETA.zip`, release token `5990-r190-india`. The SHA-256 is in section 6.

All of this was run on the test server (gate.test) with synthetic companies, rules and rates. **Nothing was run on the IONOS server.** No Indian rate, slab, section or limit in the tests was checked against an official source; the tests check that Tegh applies the rules a user enters.

## 1. What changed
See `tegh-app/R190-CHANGES.md`. In short:
- **GST on invoices:** codes are made from approved rate rules; place of supply decides CGST/SGST or IGST.
- **GST returns:** GSTR-1 and GSTR-3B working reports, registers, a GST ledger check, return periods, and GSTR-2B import and reconciliation.
- **Payroll:** user-defined pay components and salary structures, pay runs, payslips and registers.
- **TDS and TCS:** user-defined sections with their limits.
- **India Home and India Help.**
- **Backups:** the new India tables are included.

**Canadian companies:** no change (section 4).

## 2. Full gate (automated, fresh install from the R190 ZIP)

| Result | Count |
|---|---|
| PASS | 1197 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (R186 to R189): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**The container was restarted during the run** (in the UI matrix, after every suite had finished). The gate script resumed from where it stopped and completed (`logs/tail-run-before-restart.log`, `logs/tail-run.log`).

**Two groups of checks failed in the gate and passed when run again on the same R190 installation:**
- **E2E (10 checks: W3-01, W4-01, W11-01, W12-01, W13-01 to W13-03, W14-01, W18-01, W18-02).**
  - The first workflow script (`w1`) stopped before it saved anything. The invoice it creates was therefore missing, and the later steps that need it failed.
  - Re-run of the E2E workflows on a fresh synthetic company: 53 of 53 PASS (`logs/e2e.json`).
- **IN-5 (IN5-01 and UI-01).** Sign-in was refused with "Too many sign-in attempts" (the test server's login rate limit, filled by earlier suites).
  - Re-run: 10 of 10 PASS (`logs/rerun-67-in5.log`, `logs/in5.json`).
- **Common cause:** most likely the login rate limit filled by the earlier suites, for both groups. For E2E this was not proven, because `w1` recorded no error.

The totals above include the re-runs.

**Other checks:**
- path and header checks: 45 PASS, 0 FAIL;
- UI runs: 20, with no screens showing issues;
- swipe traps at 390×844: 0.

The gate ran on exactly the delivered ZIP (FILE-MANIFEST: all 444 entries OK).

## 3. India suites (inside the gate; expected figures worked out by hand in each test)

| Suite | Result | What it covers |
|---|---|---|
| 62-in1 (IN-1) | 10/10 | Edition isolation, India company, formats. UI-02 now forbids the Canadian payroll items by name, because India has its own Payroll menu from IN-5 |
| 63-in2 (IN-2) | 11/11 | India masters. IN2-02 now counts 30 rule categories (the GST return limit added) |
| 65-in3 (IN-3a) | 13/13 | GST codes from approved rules; intra- and inter-state checks; rule in force on the date; output and input tax to their accounts; GST summary equals the books; invoice form in the browser |
| 66-in4 (IN-4) | 14/14 | GSTR-1 sections; GSTR-3B 3.1, 3.2 and 4; the set-off order (unit check); registers; GST ledger check; periods (Editor prepares, Owner files with ARN, change flagged, reopen with reason); GSTR-2B (bad files refused, match / mismatch / only in 2B); screens |
| 67-in5 (IN-5) | 10/10 | Structures; proration (joining mid-month, paid days); EPF ceiling; ESIC limit; PT slabs; balanced posting; registers tie to liabilities; missing rule refused; reversal; screens and menus in both editions |
| 68-in6 (IN-6) | 12/12 | TDS limits and catch-up when the yearly limit is crossed; no-PAN rule; certificate rate; register by section and quarter; void blocked until reversed; TCS document left out of GST; India Home checklist; backup and restore of an India company (same trial balance, TDS links kept); screens |

**Faults found and fixed during this work:**
- customer invoices did not save their place of supply;
- credit notes were missing from the GST reports, because a note's tax is split only when posting;
- multi-line vendor invoices were not read line by line in the GST summary;
- reversing a pay run failed on the journal's unique source.

Screenshots: `shots/`.

## 4. Canadian before/after comparison (test server)

**Method:** the R180 database snapshot (68 companies) was restored and captured with the R190 code, India switch Off, then compared with the R189 capture (`comparison/r190-vs-r189-report.json`).

**Result: 0 differences in 592 compared files, and no status changes.**

**Screens not compared:** screens are captured for three companies per run, and the third one differed between the runs.
- **c868ff…:** screens captured by R189 only.
- **4bf220…:** screens captured by R190 only.

Their 34 screen files (17 each) could not be compared. API data for all companies was compared.

**Menus:** every Canadian menu is filtered item by item, so the new India items cannot appear in a Canadian company. `67-in5` UI-02 and `68-in6` UI-02 check this in the browser.

## 5. Not covered
- **IONOS:** no deployment or tests there.
- **Indian law:** the rates, slabs, sections and limits used are synthetic. Whether a user's rules match current law is the user's responsibility; Tegh ships none.
- **Not built yet:** see R190-CHANGES.md section 5. This includes reverse charge, printed GST invoice fields, portal upload files, projected TDS on salary, and e-invoice / e-way bill.

## 6. Package
- **SHA-256:** `b98663e7878f8ae4141d710e8618ce47fe7c04d7bf65a14053c9c067f419b9ef`
- **Size:** 445 files.
- **FILE-MANIFEST:** all 444 entries OK.
