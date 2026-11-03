# R167 — tidier sidebar submenus

R167 = R166 plus a cleaner side navigation. Server code, accounting and the database are unchanged; only browser files
changed.

## What changed

- **Submenus read as part of their module.** The sub-items are indented under the module on a thin guide line, in
  lighter, regular-weight text, so they no longer look like top-level entries.
- **No more "Activity" and "Reports" headings.** A thin divider separates the two groups. Screen readers still
  announce the group names.
- **The page you are on is marked** with a light background and an accent bar, and announced as the current page.
  The sidebar remembers which entry opened each page, for the browser tab, and marks it whenever that page is shown.
  Before, nothing in the submenu showed where you were.
- **Shorter submenus.** At 1440 × 900:
  - Receivables and Payables: 416 px → 297 px.
  - General Ledger: 287 → 185 px.
  - Payroll: 319 → 213 px.
  - With the largest submenu open, the sidebar now scrolls 24 px instead of 132 px.
- **Trial balances are named after their module.** Receivables and Payables each list their own trial balance; they
  are scoped versions, not duplicates. They now read **AR Trial Balance** and **AP Trial Balance**, which open the
  Customer and Vendor Trial Balance as before. General Ledger keeps **Trial Balance**.
- Every label fits; nothing is cut off, and the sidebar never scrolls sideways.
- Only one module is open at a time, as before. The collapsed icon rail and the Top navigation layout are unchanged.

## Files

- `assets/tegh-r157.css`: submenu layout.
- `assets/tegh-activity-r22.js`: current-page mark.
- `assets/tegh-portal-v5990.js`: AR and AP Trial Balance labels.
- `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`: cache token `5990-r167-sidenav`.
- Manifests and these notes.
