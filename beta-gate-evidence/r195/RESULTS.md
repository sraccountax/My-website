# R195: India set-up steps on the dashboard: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R195-PRIVATE-BETA.zip`, SHA-256 `a96f4e42bdcd07677faa950341f67deb73df560308ed3effd36726201cd58ae9`, 451 files. Release token `5990-r195-india-setup`. It contains R194: the Tegh T mark, Sign out on the first-company screen, and the Invoice Intelligence job-list fix.

**Where this was run:** everything here was run on the test server (gate.test) with synthetic users and companies. **Nothing was run on the IONOS server or on books-test.sraccountax.ca.**

## 1. What was reported, and what changed
**The problem:** after an India company was created, "Get your books going" showed only the general steps (bank statement, opening balances, first invoice). The India set-up was not listed: registrations, chart of accounts, GST, TDS and payroll.

**Now an India company's card lists, in order:**
1. company;
2. PAN, GSTIN and TAN;
3. chart of accounts;
4. bank accounts;
5. GST rates and GST codes;
6. TDS sections (optional);
7. payroll (optional);
8. opening balances;
9. bank statement;
10. first invoice.

Each step has a button that opens its page. Details are in `tegh-app/R195-CHANGES.md`. Canadian companies are unchanged.

## 2. Full gate (automated, fresh install of this exact ZIP)
| Result | Count |
|---|---|
| PASS | 1226 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (since R186): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**Other checks:**
- the reset ran completely: FILE-MANIFEST 450 entries OK;
- ZIP entries: all 0644;
- path and header checks: 45 PASS, 0 FAIL;
- E2E: 53/53;
- the 20 UI runs: no screens with issues.

**Suites:**

| Suite | Result |
|---|---|
| `73-r195` (new) | 3/3 |
| `72-r194` | 5/5 |
| `71-r193` | 7/7 |
| `69-in7` | 7/7 |
| `51-r177` (the new dashboard) | 24/24 |

**`73-r195` checks:**
- **R195-01:** a new Canadian company still gets its four steps, counted 1 of 4.
- **UI-01:** a new user creates an India company through the India form, and the dashboard lists the ten India steps in order. TDS and payroll are marked optional and are not counted; the highlighted next step is a required one.
- **UI-02:** each button opens its page:
  - Company details;
  - Chart of accounts;
  - India Masters (GST, TDS);
  - payroll set-up;
  - Opening balances;
  - bank statement import;
  - a new customer invoice.

A screenshot is in `shots/`.
