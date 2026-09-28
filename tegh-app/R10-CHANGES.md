# Tegh R10 Runtime Candidate

Status: **HOLD / NOT SEALED**. R10 must pass the complete GitHub runtime lab
and authenticated IONOS replay before production use.

## Repairs from R9 staging QA

- Retains CSV/XLSX bytes in browser memory as soon as a file is selected. Data
  Import and bulk Journal Import no longer depend on a temporary operating-system
  file handle surviving until the later Validate action.
- Corrects the reconciliation match-group query from invalid
  `SELECT ?, DISTINCT ...` syntax to a valid, duplicate-safe `SELECT DISTINCT`.
- Removes the legacy AR/AP `type` selector after the interactive Ageing screen is
  mapped to its concrete sealed report definition.
- Keeps the Budgets workspace and its normal no-data state when there is no
  current budget, while still registering its complete loaded export model.
- Makes generated PDF and Excel files observable and recoverable: Tegh triggers
  the browser download and retains a visible manual-save link for two minutes.
  Empty generated files are rejected explicitly.
- Allows the same Native Agent lease owner to renew after its timestamp expires
  only when no competing runner has acquired the lease row. A changed owner still
  stops the stale run.
- Removes the remaining secondary horizontal axes from Recurring Transactions,
  Analytics, Account & Access, and the Data Import review table. Cells and action
  controls wrap within the available page width.
- Uses asset revision `5990-r10` so an overwrite deployment loads the repaired
  portal, output engine, gate, preflight, and stylesheet immediately.

## Preserved contracts

- Tegh remains version 5.9.9, build 5990, Schema target 45.
- The protected Schema 45 migration and every other migration file are byte-for-
  byte unchanged from R9.
- R9's in-application confirm/prompt handling, Toronto posting calendar, payroll
  surface preservation, and common width-safe report renderer remain active.
- No staging or production data is contained in this package.

## Validation state

- Focused source/syntax suite: 21 PASS, 0 FAIL.
- Full JavaScript syntax sweep: 68 PASS, 0 FAIL.
- JSON parse sweep: 12 PASS, 0 FAIL.
- Local Chromium is unavailable in the packaging worker, so real download events
  and rendered viewport measurements are deliberately deferred to GitHub/IONOS.
  They are not represented as passed.

## Deployment

The archive is flat. Upload the unchanged ZIP to GitHub validation, then extract
that exact ZIP directly into the IONOS staging document root, overwriting the
existing Tegh files. If the retained database is already Schema 45, the protected
preflight should report no pending schema upgrade.
