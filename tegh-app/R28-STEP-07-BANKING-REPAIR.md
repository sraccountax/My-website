# R28 Step 07 — Banking Repair

Status: implemented and source-verified from the deployed Step 06B baseline.

## Delivered behavior

- **Upload Statement** is a dedicated page with no Import / Review / All Transactions strip.
- **Bank Statement Converter** is visible from Upload Statement and returns to that page.
- Approved structured imports and approved converter imports open **Match and Post Transactions** in Post mode.
- The legacy **Open Bank Review** route is removed from Banking pages and command search.
- Transfer review opens **Match and Post Transactions** in Match mode.
- The Match and Post page keeps a fixed 50-row bank-side page. Book entries remain search-driven on the right.
- **Bank Accounts → Reconcile** opens a separate **Complete Bank Reconciliation** evidence screen.
- Reconciliation completion loads statement controls and matched/unmatched evidence from the server, supports a draft snapshot, and permits completion only after all statement items are matched.
- Saved draft and completed snapshots flow into **Bank Reconciliation Report**.
- **Bank Transaction Report** now uses a fixed 50 rows per page and has no rows-per-page selector.
- The existing compact sidebar now displays each module icon and module name while continuing to hide nested Activity / Reports choices.
- Banking and report scroll regions use native smooth scrolling and respect reduced-motion preferences.

## Preserved controls

- Posting and matching remain separate actions.
- Posting does not automatically match a transaction.
- Reconciliation completion uses the existing protected `operations/reconciliation-complete` endpoint.
- A completed reconciliation still requires a zero statement-to-book difference and every in-period statement item to be matched.
- No database migration was added.

## Changed deployment files

- `app.html`
- `assets/tegh-gate-v5990.js`
- `assets/tegh-preflight-v5990.js`
- `assets/tegh-portal-v5990.js`
- `assets/tegh-registers-r23.js`
- `assets/tegh-r27.css`
- `verification/r27_verify.py`
- `verification/r28_step06_verify.py`
- `verification/r28_step07_verify.py`
- `R28-STEP-07-BANKING-REPAIR.md`

Active cache revision: `5990-r28-s7-banking-repair`

## Verification completed

- Step 07 Banking repair verifier: 31/31 passed.
- Step 06 reconciliation-report verifier: 24/24 passed.
- Cumulative R27 verifier: 34/34 passed.
- Syntax sanity suite: 25/25 passed.
- JavaScript parser checks: passed for portal, report register, gate and preflight.

Live authenticated verification is required after deployment. It must confirm Upload Statement, converter visibility, post/import routing, transfer routing, fixed-50 report pagination, compact-sidebar labels, reconciliation draft/completion behavior and the resulting reconciliation report snapshot.
