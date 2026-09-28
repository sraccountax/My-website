# R28 Step 07A — Banking UI repair

This corrective release addresses the two defects found during the live Step 07 browser test.

## Corrections

- Keeps **Bank Statement Converter** inside the visible Upload Statement form action row so the page enhancer cannot move it into the collapsed Related actions menu.
- Keeps the existing compact-sidebar design, while widening its existing navigation and footer containers to the already allocated 140-pixel content width.
- Displays full module names beside their existing icons in compact mode.
- Leaves the expanded sidebar layout unchanged.

## Retained verified behaviour

- Match and Post Transactions remains the unified banking workspace.
- The bank side is fixed at 50 rows per page; the book side is searchable without a page-size control.
- Post and Match actions enable only after the required transaction and destination selections are valid.
- Internal bank and book panes use smooth native scrolling and respect reduced-motion preferences.
- Bank Accounts continues to open Complete Bank Reconciliation.
- Banking reports include Bank Transaction Report and Bank Reconciliation Report.

## Deployment

Replace only the files included in the release archive. Do not delete unrelated application files.

Active cache revision: `5990-r28-s7a-banking-ui-repair`
