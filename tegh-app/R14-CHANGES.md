# Tegh 5.9.9 Build 5990 Schema 45 — R14 stress-repaired runtime candidate

R14 includes the two R13 interface repairs plus the defects confirmed by the R12 stress and limit campaign:

- Concurrent duplicate 250-journal imports acquire the existing company write lock before journal/voucher reads, eliminating the observed MariaDB `1213` lock-order deadlock. The database uniqueness guard remains final and the waiting duplicate now returns the stable `409 bulk_journal_duplicate` contract.
- A corrupted retained import preview rolls back completely and returns the actionable `500 import_preview_corrupt` contract.
- A valid backup container with a modified signed payload is rejected before restore with `422 backup_integrity_invalid`; no company is created.
- Day Book enriches journal lines with set-based company-scoped joins instead of repeating correlated voucher, party and bank lookups for every displayed line.
- Concurrent report audit receipts take the company audit lock with an exclusive no-op duplicate update, preventing the shared-lock upgrade deadlock reproduced by simultaneous Trial Balance outputs.
- Bank Review observes the asynchronous balance strip and remeasures during the font/layout settling window; its short-desktop header, detail spacing, helper copy and action footer are compacted so the complete default detail remains visible at 1366×768. The height calculation uses the smaller live layout/visual viewport boundary so a stale Chromium `visualViewport` value cannot size the pane for the previous viewport.
- Customer-invoice Product/Service selection preserves the current form line before applying catalogue defaults.
- Financial Analyst forecast and evidence tables fit the active page without a second horizontal scroll rail.
- The active asset cache revision is `5990-r14`, ensuring overwritten staging files are fetched immediately.

R14 does not change the database schema or migration target. It remains Schema 45.

Release state remains HOLD / NOT SEALED until the exact R14 ZIP passes the immutable disposable runtime workflow, the authenticated IONOS replay, controlled-inbox SMTP header verification, and independent accounting/security review.
