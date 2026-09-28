# R28 Step 08 — Banking Scale and Mobile Repair

## Tested load

- 2,500 synthetic CSV transactions in an isolated CAD chequing account.
- Preview validation and complete-preview virtualization.
- Import approval, idempotent recovery, Match and Post search/selection, report filtering, and fixed 50-row paging.
- Responsive banking layouts at the existing 1,050 px, 820 px, and 600 px breakpoints.

## Repairs

- CSV `Running Balance`, `Balance`, and `Account Balance` columns are retained as source balance evidence.
- Large import approval keeps one idempotency key and safely checks the same protected operation after an unknown gateway outcome.
- Match and Post shows a compact first/current/adjacent/last page window instead of rendering every page number.
- Pagination range text can no longer collapse vertically in a narrow transaction pane.
- Mobile filters, mode controls, posting form, upload actions, reconciliation filters, and paging stack without horizontal page overflow.
- Existing internal smooth scrolling and reduced-motion behaviour remain intact.

Active cache revision: `5990-r28-s8-banking-scale-mobile`
