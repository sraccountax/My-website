# Tegh R16a UI correction

Complete version 5.9.9 / Build 5990 / Schema 45 application, including all R16 UI changes. No API, database, migration, live configuration or stored accounting data is changed by this correction.

## Corrections after the R16 staging upload

- Dashboard and scenario dates use the existing dateShift helper. The missing financialShiftDate call prevented dashboard startup. The previous unit test masked this by providing that missing helper as a stub; tests now load the real dependencies.
- Removed legacy stage top padding and fixed-position header insets from the viewport layout. The header fills the available workspace width, and the old forced body scrollbar is overridden.
- Restored customer/vendor row action nodes when authoritative reports render. Actions retain their existing handlers, remain available after search is cleared, and are cached only within the current page/company scope.
- Directory search loads the server-filtered report model shared by the screen and PDF/Excel/Print. Search wording now matches the server's supported name/contact/email/phone fields.
- Condensed report context tabs and reconciliation filter controls. Financial forecast chart labels are larger, and Settings cards size to their contents.
- Cache revision is 5990-r16a across the entry point, gate and preflight.

## Validation boundary

109 local assertions pass, plus syntax checks for 67 JavaScript files and checksums for the complete package. The 12 hotfix cases were also replayed under Toronto and Auckland time zones. All 75 API files match the uploaded R16 package byte for byte.

R16 staging was tested at 1363 x 936 using the existing large-text preference. Customer internal scrolling/sticky headers, PDF and Excel generation, independent reconciliation scrolling, Financial Analyst, Settings, payroll register and unsaved pay-run form were checked. That pass also exposed the defects corrected here. No accounting records were created, posted or deleted.

R16a has not yet been uploaded or browser-tested. Mobile/alternate viewport checks, full module replay, financial/export parity, host configuration checks and the prior outstanding acceptance gates remain open. This package is a corrected staging candidate, not a claim of completed production acceptance.

## Installation

Back up the existing site and data. Extract this complete ZIP into IONOS /Books-Test, so app.html and api/ are directly inside that directory. Replace application files while preserving existing configuration, private uploads/storage and the database. Do not delete the site first. No schema upgrade is required. Reload app.html and confirm assets use 5990-r16a, then repeat the failed dashboard/header/customer checks before production use.
