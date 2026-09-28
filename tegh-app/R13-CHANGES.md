# Tegh 5.9.9 Build 5990 Schema 45 — R13 runtime candidate

R13 contains the two repairs found during the authenticated R12 staging audit:

- Customer-invoice Product/Service selection now preserves the current line before applying the selected catalogue item, so its description, unit price, taxable default and product link populate together.
- Financial Analyst forecast and evidence tables now fit the active report page at supported desktop and narrow widths. Long dates and headings wrap; the duplicate horizontal scroll rail is removed.
- The active asset cache revision is `5990-r13`, ensuring overwritten staging files are fetched immediately.

R12 itself passed the exact immutable GitHub MariaDB/API/Chromium lab with 84 PASS, 0 FAIL and 0 BLOCKED. Its authenticated IONOS replay passed statement import, two linked settlements, four exact reconciliation pairs, a zero-difference completion, all 25 report routes, Native Agent execution and the 25-check safe host scan. R13 changes no PHP, schema, posting, migration or accounting-service code.

Release state remains HOLD / NOT SEALED until the exact R13 ZIP is rerun through the disposable lab and authenticated staging checks.
