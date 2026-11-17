# R188 India edition foundation (IN-1), India master data (IN-2) and the R187 menu fix: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R188-PRIVATE-BETA.zip`, release token `5990-r188-india` (SHA-256 in section 6).

Everything here was run on the test server (gate.test) with synthetic companies. **Nothing was run on the IONOS server.**

## 1. What changed in this package

See `tegh-app/R188-CHANGES.md`. In short:
- **IN-1:** an India edition switch (off by default), edition chosen at sign-in and fixed for the session, India company creation, and India menus, money and dates with no Canadian references.
- **IN-2:** India master data under Settings › Company details › India Masters: dated profiles, dated records and user-entered statutory rules (draft until approved). The gap analysis is `india-edition/IN2-MASTER-DATA-GAP-MATRIX.md`.
- **DD-04 fix:** the Download menu button no longer animates when pressed (CSS only).

Canadian companies: no change in figures or screens (section 4).

## 2. Full gate (automated, fresh install from the R188 ZIP)

| Result | Count |
|---|---|
| PASS | 1138 |
| INFO | 13 |
| FAIL | 6 |

- **Failures:** only the six known ones, the same as R186 and R187: BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.
- **Fixed since R187:**
  - DD-04 now passes, so the R187 menu defect is fixed in this package.
  - IN-09, a timing failure in R187, also passes.
- **Increase in PASS:** from 1115 to 1138. The new suites add 21 checks (`62-in1` 10, `63-in2` 11), and the two R187 extra failures now pass.
- **Other checks:**
  - path and header checks: 45 PASS, 0 FAIL;
  - UI runs: 20, with no screens showing issues;
  - swipe traps at 390×844: 0.
- **Suite 61-r187:** 23 of 23 PASS.

The gate ran on exactly the delivered ZIP (FILE-MANIFEST: all 438 entries OK).

Logs: `logs/gate-r188a.out`, `logs/run-all.log`, `logs/tail-run.log`, `logs/in1.json`, `logs/in2.json`, `logs/r187.json`.

## 3. India suites (automated, inside the gate)

The India edition switch was turned on by the tests themselves, on the test server only. It is off by default in the package.

### 62-in1 (IN-1): 10 of 10 PASS

| ID | Check | Result |
|---|---|---|
| IN-01 | Switch Off: the sign-in screen offers no edition, and choosing India at sign-in is refused; Canada sign-in is unchanged | PASS |
| IN-02 | Switch "Platform owner only": the platform owner signs in to India; another person choosing India is refused | PASS |
| IN-03 | India company: wrong PAN, wrong GSTIN check character and a GSTIN from another state are refused; a valid one creates INR books, an April–March year and Asia/Kolkata | PASS |
| IN-04 | India company set-up has nothing Canadian: no GST/HST, PST, QST or Canadian payroll accounts, no GIFI codes, no tax codes, no Canadian payroll rates; the bank account is an INR current account | PASS |
| IN-05 | Editions never mix: India sign-in cannot open a Canadian company; Canada sign-in cannot open the India company and does not list it | PASS |
| IN-06 | The accounting core works in the India company: a ₹2,500 journal posts at once; a ₹25,000 journal waits for approval above the 10,000.00 control limit (the same rule as Canada); the trial balance balances | PASS |
| UI-01 | Sign-in screen: with the switch on "Platform owner only" the Edition choice appears only on the `?edition=in` link; India sign-in opens the India company | PASS |
| UI-02 | India menus have no Payroll, GIFI, Tax Code Report, GST/HST/PST Summary or CRA items; Bank Transfers and the guided mode are not offered | PASS |
| UI-03 | Money and dates in the India edition: ₹ with Indian digit grouping and DD-MM-YYYY | PASS |
| UI-04 | No Canadian wording, $ amounts or CAD on the India screens (Dashboard, chart, customers, invoices, Match and Post, journals, P&L, Balance Sheet, Trial Balance, Day Book, company details, add company) | PASS |

### 63-in2 (IN-2): 11 of 11 PASS

| ID | Check | Result |
|---|---|---|
| IN2-01 | Canada is unaffected: a Canadian company gets 404 on every India master route and gains no India rows | PASS |
| IN2-02 | The spec covers the matrix (7 profile types, 14 record types, 29 rule categories); a new India company has no statutory rules seeded | PASS |
| IN2-03 | Statutory rule: a bad rate, a bad link and a missing source are refused; a rule is a draft until approved; a later version ends the earlier one the day before; the same date twice is refused | PASS |
| IN2-04 | Company profile: an invalid IEC or Udyam number and unknown fields are refused; a rule reference must exist; a later effective date adds a version and keeps the earlier one | PASS |
| IN2-05 | Vendor profile and company isolation: PAN is validated; a record of another company is not found; an India vendor gets PAN, MSME and TDS section | PASS |
| IN2-06 | GST registrations: another PAN is refused for the company; a second state is added; a change with a later date supersedes the old row (end date the day before) and keeps it | PASS |
| IN2-07 | Roles: an Editor sets item HSN/SAC and records but cannot change company or account mappings or add or approve rules; HSN and UQC formats are checked | PASS |
| IN2-08 | Records refer to records: a storage location needs an active warehouse; once the warehouse is inactive it can no longer be chosen; history keeps it | PASS |
| IN2-09 | Employee, pay component, bank (IFSC) and payment-method records validate their formats; every change is in the audit log | PASS |
| UI-01 | India Masters page: in Settings for the India company only; the profile, records and rules tabs render from the spec; a rule is added as a draft and approved in the browser | PASS |
| UI-02 | Canadian company: no India Masters item in the menus | PASS |

Copies of the suites are in `tests/` (identical to `india-edition/tests/`).

Screenshots (synthetic India company): `shots/in1-india-home-1440.png`, `shots/in1-daybook-1440.png`, `shots/in2-rules-1440.png`.

## 4. Canadian before/after comparison (automated, test server)

**Method**
- Restored the R180 database snapshot (68 companies).
- Captured the reports (API) and screens with the R188 code. The India switch was off, as in the package.
- Compared the capture with R187 (`comparison/r188-vs-r187.json`) and with R180 (`comparison/r188-off-report.json`).

**R188 against R187: 0 differences.**
- 592 files compared: 558 API captures (P&L, balance sheet, trial balance, ageing, control balances, workspace and dashboard summaries) and the screens.
- No status changed.

**R188 against R180:** the same 28 screen differences as R187 against R180, all of them R187's intended changes. API: 0 differences.

**Companies captured by only one run:** the full capture skips a different company each run.
- **d103f5…:** captured by R187 but skipped by R188. It was captured again separately with R188 (`comparison/r188-pick-d103f5-vs-r187.json`).
  - 10 of its screens differ from R187, but only in the random "Support reference" line on pages that say "Choose a company first".
  - It has no API captures in either run.
- **6651d0…:** captured by R188 but skipped by R187 and R180, so there is nothing to compare it with.

## 5. Not covered

- **IONOS:** no deployment or tests there.
- **India calculations:** nothing in R188 calculates or posts GST, TDS/TCS or payroll from the India masters. Those are later releases (IN-3 to IN-6).
- **Statutory rule content:** Tegh ships no Indian rates, sections, thresholds or due dates. The tests used synthetic values only; none was checked against an official source.
- **Government services:** no GSTN, e-invoice, e-way bill, TRACES or bank API is called or assumed.
- **Approval limit in INR:** an India company uses the same control limit as Canada (10,000.00 in the company currency). No screen changes it.

## 6. Package

- SHA-256 `d0ad0f00122b69e884ff239fc5e8f85f5f70fc64b9c5679685f74b5d5cbcba10`; 439 files; FILE-MANIFEST: all 438 entries OK.
- Files compared with R187: R187's files plus `R188-CHANGES.md`, `api/edition_in1.php` and `api/india_masters_in2.php`.
- The full gate in section 2 ran on this exact file.
