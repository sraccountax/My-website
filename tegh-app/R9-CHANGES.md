# Tegh R9 Runtime Candidate

Status: **HOLD / NOT SEALED**. Do not deploy to production until the complete
runtime lab, IONOS staging replay, and required reviews pass.

## Cumulative QA repairs

- Replaces blocking browser-native confirm, prompt, and alert surfaces with an
  accessible in-application action dialog or non-blocking notice. Legacy actions
  are safely replayed once after approval.
- Preserves Pay Run Register, Payroll History, Payroll Verification, and Quick
  Payroll controls instead of replacing their interactive tables.
- Keeps a completed Quick Payroll calculation and its PDF/output actions visible.
- Corrects Receivable Ageing and Payable Ageing professional-output routes so a
  valid zero-data result remains a normal empty report.
- Uses the `America/Toronto` calendar in the browser, matching the server's
  posting and report cutoff date.
- Applies one width-safe renderer across all 39 routed report surfaces: desktop
  tables fit the available page width, while tablet and phone tables stack into
  labelled records without horizontal scrolling.
- Uses asset revision `5990-r9` so the changed portal, output, and stylesheet
  load immediately after an overwrite deployment.

## Deployment

The archive is flat. Extract it directly into the staging document root and
overwrite the existing Tegh files. The same unchanged ZIP is the GitHub
validation input and the IONOS staging input.

Schema target remains 45. If the staging database is already Schema 45, no
additional schema change is expected.
