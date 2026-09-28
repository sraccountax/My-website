# Tegh R28 Step 15 — Live Review Repair

This cumulative package applies the approved repairs from the September 21, 2026 Step 14 live-browser review. It preserves Schema 46 and all existing accounting, posting, tax, payroll, bank-matching, period-lock and company-isolation logic.

## Implemented

- Institution-editor **Cancel** now returns to Manage Bank Accounts and restores the prior account selection, search and scroll context.
- The `headingSet is not defined` runtime error is removed. Headings use heading case; buttons, labels and table headings use consistent title case.
- The bank-account action now says **Add Institution** when blank and **Edit Institution** when populated.
- **Add Bank Account** is exposed as a compact page-heading action.
- Bank General Ledger and Bank Transaction reports prioritize readable complete headers, including Entry Number, Reference, Running Balance, Bank Account, Money Out, Money In and Status. Secondary columns remain available through Details and Columns.
- Invoice register rows expose one compact **Actions** control; row details and all document/source actions remain available inside it.
- A current-report loading state immediately replaces old rows, preventing a previous report layout from flashing while the new sealed result loads.
- At desktop widths, the two bank report titles and filters share the compact heading row where space permits; smaller widths retain safe wrapping.

## Deployment

Replace the full application with this cumulative ZIP. No database migration is required. Confirm that `app.html`, the active gate and the preflight loader use cache revision `5990-r28-s15-live-review-repair`.

## Required Hosted Acceptance

- Re-test both institution states (empty and populated), including Cancel with and without typed changes.
- Confirm no application `ReferenceError` occurs across dynamic navigation and rerenders.
- Verify bank report headers at 1363 × 936 and 1920 × 1080.
- Confirm invoice rows show one Actions control and that Details, print/PDF/Excel and source actions remain reachable.
- Confirm report changes show the loading state and never flash prior rows.

