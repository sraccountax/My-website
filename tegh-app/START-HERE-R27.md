# Tegh R27 staging deployment

**Cumulative full application. Not production sealed.**

Identity: **Tegh 5.9.9 / Build 5990 / Schema 46 / R27**  
Client cache: **5990-r27**  
Exact R26 source SHA-256: `507e436a9f6b9cc6fe0a78690fec088d1707717987d271c2869b10509134efe3`

## Deploy to staging

1. Take a coordinated backup of the current application files, Schema 46 database and private document storage.
2. Confirm the protected preflight reports Schema 46. R27 has no schema migration.
3. Preserve server configuration, private uploads, logs and credentials. Extract the complete R27 ZIP into the application root containing `app.html`; do not deploy selected files or run fresh-install SQL.
4. Hard-refresh a new browser session. Confirm `/tegh-build.json` reports R27, Schema 46 and cache `5990-r27`, and confirm `assets/tegh-r27.css` and `assets/tegh-r27.js` load successfully.

## Required acceptance before production

- Run every viewport, navigation mode, theme and zoom combination listed in the R27 master prompt with a populated fictional company.
- Verify wheel/trackpad, scrollbar drag, PageDown/PageUp, Home/End and last-content reachability after navigation, modal close, failed requests, sidebar toggles and resize.
- Verify Bank Reconciliation one-line rows, independent panes, Match versus Post & Match accounting effects and R26 next-row continuity.
- Verify Ctrl/Cmd+K text entry, results, arrows, Enter, Escape and focus restoration.
- Compare customer/vendor registers, column ordering, actions, pagination, filtering and complete exports.
- Sign out/in and use a second browser session to confirm Quick Actions and theme preferences are server-restored.
- Confirm Day Book counterpart accounts against journal lines for bank interest, customer revenue and multi-account entries.
- Run routine form-fit and keyboard-only flows, including error recovery and modal/drawer close paths.
- Compare server totals before/after for identical report scopes and inspect full PDF/Excel/CSV output independently of visible rows.
- Rerun invoice/bill posting, payroll, manual opening balances, interbank, bank review/reconciliation, imports, period locks, permissions, isolation and idempotency against MariaDB.
- Regenerate exact R27 Preferences preview images from the accepted implementation.

Do not mark production SEALED until the P0 runtime, accounting-integrity and authenticated hosted gates all pass.

## Rollback

Follow `ROLLBACK-R27.md`. Restore a coordinated full backup; never mix R26 and R27 active assets.
