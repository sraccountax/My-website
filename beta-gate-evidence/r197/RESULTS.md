# R197: Payroll pay heads and pay tables (India): results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R197-PRIVATE-BETA.zip`, SHA-256 `8345c9026ba4b50245a1b6c43729cfc737973767753314ccf3b09e45f130283e`, 454 files (R196 plus `R197-CHANGES.md`). Release token `5990-r197-pay-heads`. Source commit `d9879ec`. Built with every file 0644 (`ZIP-PERMS: 0 file entries not 0644`).

All testing was done on the local gate host (`/srv/gate`, synthetic data only). Nothing was deployed to IONOS or books-test, and no production data was touched.

## Full gate on this exact ZIP
| | Count |
|---|---|
| PASS | 1241 |
| INFO | 13 |
| FAIL | 6 |

The six failures are the known open items from earlier releases, unchanged by R197:
- BS-07, BS-09: public policy pages;
- PW-01 and WR-09;
- DI-SCAN: OCR scanner on the gate host;
- DD-01: menu click timing.

Raw results are in `gate/`.

An earlier attempt was discarded and is not counted: two gate runs overlapped and reset the database under each other. The figures above come from one clean run.

## Suite 75-r197 (new): 10 employees × 10 payrolls — 8/8 PASS
- **R197-01:** fixed heads created. Own heads can be added (Night Shift Allowance, Canteen), renamed and removed. Fixed heads cannot be renamed or removed, and duplicate names are refused.
- **R197-02:** ten employees saved with pay tables. They use monthly, annual, semi-monthly and bi-weekly amounts, plus percentages of Basic, DP, DA, their sums and gross. Three bad inputs are refused:
  - an earning as a % of gross;
  - a cycle between heads;
  - an unknown basis.
- **R197-03:** changes during the year (effective dated). Asha's loan stops from October, and Gita's pay is revised from September.
- **R197-04:** ten monthly payrolls, April 2026 to January 2027. Employee counts per month are 9, 9, 10, 10, 10, 10, 10, 10, 9 and 9 (joiners and leavers included). There are 0 mismatches against an independent calculation of every head of every employee-month.
- **R197-05:** each of the 10 runs posts one balanced journal, and payables equal the run totals.
- **R197-06:** the registers equal the sum of the runs:

  | | ₹ |
  |---|---|
  | Gross | 34,60,830.86 |
  | Deductions | 4,43,526.00 |
  | Net | 30,17,304.86 |
  | Employer | 1,54,515.00 |

- **UI-01:** the set-up list shows 10 employees with monthly gross, deductions and net. Editing shows the values as entered, for example Basic 25,000 semi-monthly = ₹50,000, and DA 46% of Basic + DP.
- **UI-02:** payslips open for the January run, showing each head and how it was worked out.

## Issues found and fixed during testing
1. **EPF on Basic + DA refused when no DA is paid.** Saving an employee without Dearness Allowance failed: "EPF (employee) is a percentage of Dearness Allowance, which is not in this structure". Now a head the employee is not paid counts as zero in a "% of" base. Saving is refused only when none of the base heads is paid.
2. **Pay from did not follow the joining date.** On a new employee, Pay from defaulted to today and did not follow a changed joining date, so a back-dated hire was paid only from today. Now Pay from follows the joining date unless the user changes it.
3. **Preview error on an empty form.** The live preview showed an error before any earning was entered. It now waits for an earning and shows problems as a quiet note.

Suite 74-r196 (UI-02 to UI-05) was updated to the new pay table and passes 7/7. Suite 67-in5 (older salary structures) passes 10/10 unchanged.

## Screenshots (`shots/`)
- **Employee list:** `r197-list-1440.png`, `r197-list-390.png`.
- **Pay table:** `r197-form-1440.png`, `r197-form-390.png`, `r197-edit-dev-1440.png`.
- **Phone overflow:** none at 390 px. Element screenshots on the phone stop at the app's scroll container, so only part of the form is visible in `r197-form-390.png`.
