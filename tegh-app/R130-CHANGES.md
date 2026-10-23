# R130 changes over R129: Payroll Support

5.9.9 / Build 5990 / Schema 46. Cache token `r130-tegh`. No manual database migration. See "Stored SINs are cleared" for the one automatic data change.

This is a limited change to the payroll area. Payroll is not redesigned or expanded. Calculations, journals, employee records, pay-run history, permissions and accounting integration are unchanged, except where listed below.

## 1. Renamed to Payroll Support
The module is now called **Payroll Support** in:
- the sidebar and top navigation;
- the module page title and its "Back to Payroll Support Dashboard" buttons;
- the workflow bar above payroll screens;
- the React payroll screen: heading "Payroll calculations and accounting records", and its navigation item;
- the Reports list group;
- Tegh Assist.

Internal keys (routes, permissions, search ids) are unchanged, so bookmarks, permissions and Assist commands keep working.

## 2–3. No Social Insurance Numbers
**Removed from:**
- the employee create and edit forms (both the Employees screen and the React payroll screen);
- the "SIN ••• 1234" tag in the employee list;
- the workspace data sent to the browser;
- the T4 working paper (JSON and CSV "SIN last 4" column);
- backups;
- the payroll agent's readiness checks. A missing SIN no longer counts as an incomplete profile, so employees are not falsely flagged.

**Refused:** the server refuses to store a SIN (422 `sin_not_accepted`) when:
- a `sin` / `socialInsuranceNumber` / `sinLastFour` value is sent;
- the Employee ID, name or email looks like a SIN (nine digits in 3-3-3 or straight form that pass the SIN checksum).

A nine-digit number that does not pass the checksum is still accepted as an Employee ID.

**Employee import:** SIN values, and any field that looks like a SIN, are removed *before* the import preview is saved. The row is reported as needing correction.

**Old backups:** restoring an older backup drops the SIN columns.

**Stored SINs are cleared:** the first payroll request for a company clears any SINs saved by earlier releases. It is recorded in that company's audit trail as `payroll.sin_data_removed`, with the count. The `sin_*` columns remain in the table for compatibility but are always empty. The schema file marks them unused.

**Employee ID:**
- The form label is **Employee ID**.
- New employees get the next free ID (EMP-0001, EMP-0002 …), which you can change.
- A hint reads "Internal ID such as EMP-0001. Tegh does not store SINs."

## 4. Persistent notice
Every Payroll Support screen shows this notice at the top (in light and dark mode):

> **Payroll Support Tool**
> Tegh Payroll Support is intended to assist with payroll calculations and accounting records. Do not enter or store Social Insurance Numbers (SINs) in Tegh. Payroll calculations and statutory deductions should be independently reviewed before payroll is finalized.

This covers the module page, Payroll Center, Quick Calculation, Employees, Pay Run Register, Verification, Remittance Records, Payroll History and the React payroll screen.

## 5. Review confirmation before finalizing
- **Pay Run Register:** the old two browser prompts are replaced by one dialog. It asks for the verification reference and a required checkbox: "I have reviewed and verified the payroll calculations and statutory deductions." The button stays disabled until both are filled.
- **React payroll screen:** its existing checkbox now uses the same wording.
- **Server check:** the server refuses a finalize request without `reviewConfirmed: true` (422 `payroll_review_confirmation_required`).
- **Audit trail:** the confirmation text is recorded on the audit entry.

## 6–8. Wording
| Before | Now |
|---|---|
| "2026 rate release · CRA-T4127-2026.2-verified-tax" | "Calculated using configured payroll rules (2026 rate release). Review before finalizing." |
| "Only an owner-finalized, CRA-verified run…" | "Only an owner-finalized, reviewed run…" |
| "CRA tax verified" / "CRA tax amount recorded" | "Income tax checked" / "Income tax amount recorded" |
| "CRA Remittance", "CRA payroll remittance", "Record CRA Payroll Remittance" | "Remittance Records", "Payroll remittance records", "Record a Payroll Remittance Payment" |
| "Pay + remit" (workflow step) | "Record payments" |
| Remittance pages | now say the payment was made outside Tegh, and "Tegh does not send payments or returns to CRA" |
| "remain in Payroll for year-end filing" | "remain in Payroll Support for year-end reference" |

**Unchanged:**
- The existing statements that Tegh does not do direct deposit, ROE, T4 slips/XML or CRA transmission.
- The "do not enter My Business Account credentials" hint.

A search found no wording claiming full compliance, CRA approval or certification, or guaranteed accuracy.

## Verified
Tested with Playwright (Chromium) on local PHP 8.4 and MariaDB.

- **Create/edit employee:**
  - New employee gets EMP-0001, then EMP-0002; saved.
  - Edits saved from both the Employees screen and the React screen.
  - The forms have no SIN field.
  - Refusals: a SIN field (422), `socialInsuranceNumber` (422), a SIN as Employee ID (422). A plain 9-digit ID is accepted.
- **Stored SIN clean-up:** a test SIN placed on an employee was cleared on the next payroll request, and the audit entry recorded 1 employee.
- **Payroll calculation:** Quick Calculation for 4 employees (ON, BC, AB with year-to-date amounts, NS) gives byte-identical results on R129 and R130 code.
- **Finalizing a pay run:**
  - A 4-employee draft was created and income tax recorded.
  - Finalizing without the confirmation is refused (422).
  - The button stays disabled with only the reference, and turns on after the checkbox. The run moves to verified / ready to post.
  - The React path works the same way.
- **Payroll journal:** Post to GL creates the journal:
  - Dr 7000 Wages $8,460.00 and Dr 7010 Employer contributions $664.39;
  - Cr 2300 Net pay $6,719.31, 2310 Income tax $1,131.46, 2320 CPP $942.66 and 2330 EI $330.96;
  - balanced at $9,124.39.
- **Reports and exports:**
  - Pay Run Register, Pay Run Detail and Payroll Remittances reports have no SIN column.
  - The T4 working paper JSON and CSV have no SIN.
  - A backup export has no SIN columns.
  - Restoring a row that carries SIN values stores none.
  - An employee import with a SIN is rejected, and the stored preview holds no SIN.
- **Permissions:** owner, admin, editor and viewer get the same results on R129 and R130 code:
  - viewer is refused payroll;
  - editor can view and add employees but cannot finalize or post;
  - owner and admin can do all of it.
- **Layout:**
  - notice, employee form and finalize dialog checked at 1440×900 and 390×844, light and dark, with no sideways scrolling;
  - the dark-mode contrast check on payroll screens is clean. This included fixing the "Verified" status pill, which was unreadable in dark mode.
- **Regression:**
  - all 46 menu screens load on desktop and phone with no errors or overflow;
  - idle page activity is 1 change;
  - both Tegh Assist test sets pass (54/54 and 45/45);
  - PHP and JS syntax checks pass for every file.

## Files
- **Server:**
  - `api/payroll.php`
  - `api/data_imports.php`
  - `api/backup.php`
  - `api/payroll_tax_agent.php`
  - `api/native_agents.php`
  - `api/report_loaders_v5980.php`
  - `api/ai_actions_v5600.php`
  - `api/ai_agent.php`
  - `api/assist_language_r123.php`
  - `api/schema.sql` (column comments only)
- **Browser:**
  - `assets/tegh-portal-v5990.js`
  - `assets/index-BsxPiq85-v2817.js`
  - `assets/index-BsxPiq85-v211.js`
  - `assets/tegh-workflow-v5700.js`
  - `assets/tegh-r120.css` (section 27)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R130-CHANGES.md`
