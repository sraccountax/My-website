# Tegh R26 — staging deployment

**Cumulative application candidate. Not production sealed.**

Identity: **Tegh 5.9.9 / Build 5990 / Schema 46 / R26**  
Client cache: **5990-r26**

Exact baseline: R25 ZIP SHA-256 `1029e4b4ddc7603614094e8314a0b1083de6bc0997b2c5425278acdd4a95bb97`.

## Before deployment

1. Deploy to staging first and take a coordinated backup of application files, database and private document storage.
2. Confirm the database is already Schema 46 using the existing protected Platform Owner preflight. R26 introduces **no new schema migration**.
3. Preserve private configuration, uploaded documents, incident logs and server-specific files. Upload this cumulative ZIP into the existing application root containing `app.html`; do not run fresh-install SQL over retained data.
4. Open a fresh browser tab or hard-refresh after deployment. Confirm `/tegh-build.json` identifies **R26 / Schema 46 / cache 5990-r26** and that `assets/tegh-r26.css` loads.

## Required R26 staging checks

Use a fictional QA company and real authenticated roles.

- **Bank Review:** import or seed at least ten pending transactions. Process a middle row while earlier rows remain. Confirm the next transaction is selected after the server refresh and that duplicate clicks do not double-post.
- **Bank Reconciliation:** match/post a middle bank row and confirm the next unmatched row is selected. Check Match versus Post & Match accounting effects independently.
- **Column ordering:** reorder invoice/report columns by drag and by the keyboard-accessible chooser controls, reload the report, and verify display and export order.
- **Dashboard:** scroll the Quick Actions list with wheel/trackpad and keyboard where appropriate; all actions must remain reachable.
- **Inline filters:** verify From / date / To / date and bank-credit selectors at the user's actual laptop viewport and 125%, 150% and 200% browser zoom.
- **Interbank clearing:** verify account 1070 is present for a newly created QA company. For an existing QA company without it, add it only after explicit confirmation. Post controlled test legs and confirm the clearing account returns to zero only when both balanced legs have been correctly recorded. Do not combine the clearing and linked-transfer methods for the same transfer.
- **Invoice Actions:** open/close the row Actions dropdown repeatedly. It must not expand the invoice detail row; More details remains separate.
- **Reconciliation density:** verify each normal transaction is a compact one-line row and expanded evidence remains reachable.
- **Ageing:** run AR and AP ageing for All and for an individual customer/vendor; reconcile filtered totals to the underlying documents/opening balances.
- **Day Book:** verify collapsed default, individual expand/collapse, Expand all, Collapse all, last record reachability and complete export.
- **Regression:** rerun manual opening balances, invoice Send/Edit/Attachments, monthly/prior/variance P&L, import/reconciliation/interbank safeguards and permission-limited roles.

## Rollback

If Schema 46 and private-storage format are unchanged, restore the complete exact R25 managed application backup and its cache revision. Never roll back only selected JavaScript files. If any database/private-storage change requires rollback, restore the coordinated matching application/database/private-storage backup.
