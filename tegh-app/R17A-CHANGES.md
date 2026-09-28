# Tegh R17a invoice-posting hotfix

Complete Tegh version 5.9.9 / Build 5990 / Schema 45 application based on the R17 responsive workspace. R17a corrects normal customer- and vendor-invoice posting after the Schema 45 AR/AP control-account mappings were installed. No database migration is required.

## Corrected defect

Schema 45 registers Accounts Receivable (1200) and Accounts Payable (2050) as authoritative module control accounts. The journal safety guard was using a generic “system account” lookup, so it incorrectly reported either control as Opening Balance Control 9999. Because every accrual customer invoice debits 1200 and every accrual vendor invoice credits 2050, normal invoice posting was blocked regardless of a corrected transaction date.

R17a narrows that guard to the actual `opening_balance_control` system key and account code 9999. AR and AP remain protected module controls and are now usable by their authorized customer/vendor workflows. Account 9999 remains unavailable to every normal transaction.

## Start-of-books behavior

- A current-period customer invoice must be dated on or after the company Start of Books date.
- A current-period vendor invoice must be dated on or after the company Start of Books date.
- Earlier unpaid customer invoices must be entered through **Data Import → Opening Customer Invoices**.
- Earlier unpaid vendor invoices must be entered through **Data Import → Opening Vendor Bills**.
- Both invoice forms now set their minimum date from Start of Books and explain the correct opening-document workflow.
- Existing pre-start drafts are checked again when issued or posted, so they cannot bypass cutover controls.

## Additional hardening

- Vendor expense/asset choices explicitly exclude account code 9999 and any system-control account, including the quick Add Vendor flow.
- The browser asset revision is `5990-r17a`, ensuring overwritten IONOS files are requested immediately after deployment.
- All R17 UI, reporting, chart, reconciliation and responsive changes remain included.

## Files changed from R17

- `api/accounting.php`
- `api/books.php`
- `api/master_data.php`
- `assets/tegh-portal-v5990.js`
- `assets/tegh-gate-v5990.js`
- `assets/tegh-preflight-v5990.js`
- `app.html`
- `tegh-build.json`
- `RELEASE-MANIFEST.json`
- `R17A-CHANGES.md` (new)
- `FILE-MANIFEST.sha256` (regenerated for the exact package)

## Validation status

- 26/26 focused AR/AP and Start-of-Books hotfix checks pass.
- 136/136 unique prior R17 source and transformation assertions pass.
- 12/12 additional timezone replay assertions pass.
- All packaged JavaScript files pass Node syntax checks.
- All packaged JSON files parse successfully.
- PHP command-line syntax checking and live MariaDB/browser posting are not available in this build workspace. After uploading this exact package to IONOS staging, post one current-period customer invoice and one current-period vendor invoice and verify both Day Book entries before production promotion.

## Deployment

Back up the current site and database. Extract the complete ZIP into the IONOS `/Books-Test` directory so `app.html` and `api/` are directly inside it. Replace application files while preserving the live configuration, private uploads/storage and database. Do not delete the site first. Reload `app.html` and verify that active assets use cache revision `5990-r17a`.
