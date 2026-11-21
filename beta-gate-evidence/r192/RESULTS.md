# R192 functional and UI audit of the Canada and India editions: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R192-PRIVATE-BETA.zip`, release token `5990-r192-audit`. The SHA-256 is in section 7.

Everything here was run on the test server (gate.test) with synthetic companies, or with the synthetic R180 database snapshot. **Nothing was run on the IONOS server.**

## 1. How the audit was done
`audit-sweep.mjs` (in `tests/`) signs in as a browser user and works through one company. It covers:
- every sidebar menu screen and the first New/Add/Create form on it;
- every settings and tool page;
- every report in the report catalogue.

It runs in four combinations:
- a Canadian company and an India company;
- each at 1440 px and 390 px.

**What it records for each screen:**
- browser errors and failed server calls (HTTP 400 and above);
- page overflow and controls off screen;
- cut-off headings and buttons;
- unlabelled fields;
- text such as "undefined" or "NaN";
- the other edition's wording.

Each finding was then checked by hand, and the faults were fixed (`tegh-app/R192-CHANGES.md`). The new suite `70-r192` checks each fix.

## 2. Faults found and fixed
**India edition:**
- **Company details:** registrations saved together with a time-zone change were dated in the old zone. When the two zones were on different dates, the old registrations kept showing.
- **Wording and dates:**
  - "Fiscal year" and "GST/HST" wording appeared on shared screens;
  - period lists did not say "financial year";
  - period dates were not shown as DD-MM-YYYY.
- **Payroll pages:**
  - they showed the Canadian payroll notice;
  - they called the Canadian payroll service, which failed for an INR company.
- **Canada-only screens** opened in an India company: CRA payroll, tax codes, sales tax rates and GIFI.
- **Recurring documents:**
  - the forms offered "Apply GST/HST" and "Apply PST";
  - the server accepted those taxes in an India company.
- **Expenses:**
  - the expense page and the Expense Register had a PST column;
  - the bill tax-code hint named PST.
- **Data import:**
  - Indian states were refused (they were checked against Canadian provinces);
  - a blank country became Canada.
- **Province instead of State:** the report builder and Tegh Assist's Create Customer form said "Province".

**Both editions:**
- **Match and Post on a phone:**
  - dates and amounts were cut off;
  - the Status heading was cut off.
- **Match and Post Actions menu on a phone:** scrolling the table closed the menu as it opened. This was found by the R186 phone test in the first R192 gate, after the table was made to scroll sideways.
- **Report scope line:** the title and the period ran together ("Expense registerJan 1, 2026").
- **Users:** the role lists had no accessible name.

## 3. Full gate (automated, fresh install of this exact ZIP)
| Result | Count |
|---|---|
| PASS | 1211 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (R186 to R191): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**No re-runs were needed.** The gate script clears the login attempts before each India suite.

**Other checks:**
- E2E: 53/53;
- path and header checks: 45 PASS, 0 FAIL;
- FILE-MANIFEST: all 446 entries OK.

**Suites:**

| Suite | Result |
|---|---|
| 60-r186 (Match and Post) | 21/21 |
| 62-in1 | 10/10 |
| 63-in2 | 11/11 |
| 65-in3 | 13/13 |
| 66-in4 | 14/14 |
| 67-in5 | 10/10 |
| 68-in6 | 12/12 |
| 69-in7 | 7/7 |
| 70-r192 (new) | 7/7 |

**Earlier builds of R192:**
- **First gate** (`logs/first-gate/`): the same plus one more failure, R186 UI-04. The Actions menu did not stay open at 390 px. This was fixed.
- **Second gate** (`logs/second-gate/`): only the six known failures.
- **The sweeps then found** the cut-off Status heading. That fix is the only difference between the second build and this one.

## 4. What 70-r192 checks
- **R192-01:** company details with a time-zone change. The zones used (Pacific/Kiritimati to Pacific/Pago_Pago) are always on different dates. The new registrations show at once.
- **R192-02:** recurring invoices and vendor invoices with GST/HST or PST:
  - refused in an India company (422 `india_recurring_gst`);
  - accepted in a Canadian company (201).
- **R192-03:** an India customer and vendor import:
  - Indian states are accepted;
  - a blank country becomes India.
- **R192-04:**
  - the India Expense Register has a GST column and no PST;
  - the Canadian register is unchanged;
  - the report builder labels the region "State" in India only.
- **UI-01, India browser checks:**
  - financial-year period options;
  - DD-MM-YYYY dates;
  - no payroll notice;
  - the Canada-only page for tax codes;
  - no GST/HST or PST in the recurring forms.
- **UI-02, Canadian browser checks:** the same screens unchanged, including GST/HST, PST and the Canadian payroll screens.
- **UI-03, 390 px in both editions:** in Match and Post, dates, amounts and column headings are shown in full, and the table scrolls sideways. The report scope line reads "title · period".

## 5. Final audit sweeps (`audit/`)
Run on the test server with the final code, on the R180 snapshot plus companies made by the India suites:
- **Canada:** Gate Test BC Ltd;
- **India:** a synthetic GST company from IN-3.

| Sweep | Screens | Forms | Flags |
|---|---|---|---|
| Canada 1440 px | 115 | 14 | 4 |
| Canada 390 px | 115 | 14 | 4 |
| India 1440 px | 118 | 12 | 8 |
| India 390 px | 118 | 12 | 8 |

**The remaining flags are wording checks, reviewed and left by design.**

Canada:
- the CGST/SGST example in the Tax Codes help text (written in R137, before the India edition, as an example of a custom code);
- "TDS" on Users, which is the name of an India test company in the member's company list.

India:
- "Canada" in the country list of the customer and vendor forms;
- the site-wide Site & Release Settings page;
- the Canada-only notice pages, which say "Canadian" by design.

**What the sweeps found nowhere:** browser errors, failed server calls, page overflow, cut-off headings or buttons, unlabelled fields, "undefined" or "NaN".

**Test-setup note:** an earlier pass of the Canadian sweeps returned HTTP 500 on reports. The cause was the audit log's own check ("Audit clock moved backwards"). The restored R180 snapshot holds audit entries dated 17:00 UTC on the pinned test day, and the test clock stood at 16:32. The product correctly refused to write audit entries before the last one.
- **Fix:** the test clock was moved to 13:30 Toronto (17:30 UTC) and the sweeps were run again.
- **Same cause:** IN7-05 and R192-02/03/04 failed on that database before the clock change. They passed after it (`audit/in7-r180db.json`, `audit/r192-r180db.json`), as they did in the full gate.
- **No product change** was made for this.

## 6. Canadian before/after comparison (test server)
**Method:**
1. The R180 database snapshot (68 companies) was restored.
2. It was captured with the R192 code (second build), India switch Off.
3. The capture was compared with the R191 capture (`comparison/r192-vs-r191-report.json`).

**Result: 592 files compared, 10 different, 0 status changes.**
- **The 10 differences:** all are the report scope line, now with the separator. For example, "Trial BalanceOct 1, 2026" became "Trial Balance · Oct 1, 2026". This applies to the Trial Balance, Balance Sheet, Cash Flow, Receivable Ageing and Payable Ageing screens.
- **No figure changed.**
- **The final build** differs from the compared build only by a CSS rule for screens up to 820 px. That rule is not part of the captured text.

**Screens not compared:** the third company whose screens are captured differs between runs, so its 34 screen files were not compared. API data for all companies was compared.

## 7. Not covered
- **IONOS:** no deployment or tests there.
- **Browser coverage:** the sweeps used Chromium only. Safari and Firefox were not checked.
- **Sweep depth:** the sweeps open each screen and its first form. They do not submit every form; the suites cover the workflows.
- **IN-7 provider integration:** e-invoice and e-way bill wait for the provider account.

## 8. Package
- **SHA-256:** `53e7c39bcb32258e8d29125d80a434e3eb6a81c2ba1231defdd0479ca7193c95`
- **Size:** 447 files.
- **FILE-MANIFEST:** all 446 entries OK.
