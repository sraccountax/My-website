# R28 Step 06 — Bank Reconciliation Report

Status: implemented and source-verified from the exact Step 05B deployment baseline.

## Delivered behavior

- Banking Workspace continues to expose **Bank Reconciliation Report** under Reports.
- The report now opens a dedicated, read-only reconciliation history instead of the generic summary table.
- History is filtered on the server by financial account, date range, status and search text.
- History uses a fixed 50-row server page. There is no rows-per-page control.
- Draft, reconciled and reopened states are distinct.
- Selecting **View report** loads only that reconciliation's detail snapshot.
- The detail view includes:
  - bank statement ending balance;
  - deposits in transit;
  - outstanding payments / withdrawals;
  - adjusted bank balance;
  - Book / GL balance and adjusted book balance;
  - unreconciled difference;
  - cleared and matched transactions;
  - unmatched bank and book entries;
  - explicit adjustment and bank-evidence sections;
  - reconciliation audit history and exceptions.
- PDF, Excel, CSV and Print use the same server-originated snapshot reference and integer-cent values displayed on screen.
- All report paths remain read-only. They do not post, match, clear, reopen or alter accounting records.

## Accounting and control safeguards

- Detail is read under a repeatable-read transaction.
- Company and permission access are checked before the read and rechecked before releasing the response.
- Completed balances remain stored historical evidence; current book changes are disclosed as exceptions instead of silently restating the snapshot.
- Changed match evidence, missing cleared-item match evidence, disputed statement evidence and bridge/stored-difference conflicts are explicitly flagged.
- The current completion contract is unchanged.

## Performance and layout

- History returns at most 50 records per request.
- Transaction detail loads only after a reconciliation is selected.
- History and detail tables scroll internally with smooth scrolling.
- `prefers-reduced-motion` changes scrolling to automatic.
- The balance bridge remains two-column at desktop size and stacks on narrower displays.

## Changed deployment files

- `app.html`
- `assets/tegh-gate-v5990.js`
- `assets/tegh-preflight-v5990.js`
- `assets/tegh-portal-v5990.js`
- `assets/tegh-r27.css`
- `api/operations.php`
- `api/report_loaders_v5980.php`
- `api/report_output_v5980.php`

Active cache revision: `5990-r28-s6b-banking-report-route`

Live QA follow-up: the report title is explicitly kept on one line at compact desktop widths.

Reloading either Banking report now retains Banking as the active sidebar module instead of switching to the general Reports module.

## Verification completed

- Step 06 acceptance verifier: 22/22 passed.
- Cumulative R27 verifier: 34/34 passed.
- Syntax sanity suite: 25/25 passed.
- JavaScript parser check: passed.

Live authenticated verification is required after deployment. It must confirm the cache revision, history API response, detail contract `2.0`, balance arithmetic, internal scrolling, report exports and absence of accounting writes.
