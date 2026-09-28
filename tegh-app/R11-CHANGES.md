# Tegh 5.9.9 Build 5990 Schema 45 — R11 runtime candidate

R11 contains four evidence-driven corrections found during authenticated R10 staging QA:

- Confirmation and prompt dialogs no longer overwrite their stored source action when their own controls are used. Continue now replays the original action exactly once, Cancel performs no replay, and Escape is consumed by the visible Tegh dialog before an underlying modal can react.
- Native Agent lease renewal now verifies the lease row after updating it. A valid same-second MySQL no-op is no longer misclassified as a lost lease, while a changed owner or expired row still stops the run.
- Customer invoices, vendor invoices, products and services, expense vouchers, bank accounts, bank reconciliation, inventory, and customer/vendor ledger tables are constrained to the active page width after all legacy CSS so those screens do not require horizontal side scrolling.
- The active asset cache revision is `5990-r11`, ensuring overwritten staging files are fetched immediately.

No Schema 45 migration file or checksum was changed. The release remains HOLD / NOT SEALED until this exact ZIP passes the complete disposable runtime lab and authenticated IONOS replay.
