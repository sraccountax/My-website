# R28 Step 10 — Banking Table Visibility Repair

Active cache revision: `5990-r28-s10-banking-table-visibility`

## Defect

The Match and Post Transactions bank and book tables received the legacy
`r22-stacked-table` enhancement. Its unconditional grid/card rules changed
each table row into a two-column CSS grid. The R27 compact banking rules then
limited that grid to 38 pixels, leaving individual cells approximately eight
pixels high. The rows existed and were accessible to scripts, but their text
was visibly clipped on the real banking page.

## Repair

- Restore native table, header-group, row-group, row and cell display modes.
- Keep the repair scoped to `.r28-banking-workspace` so other intentionally
  stacked mobile tables are unchanged.
- Preserve the compact 38-pixel row, ellipsis behavior, numeric alignment and
  independent bank/book scroll containers.
- Advance the cache revision so browsers cannot retain the defective CSS.

## Safety

This is a presentation-only repair. It does not change bank data, matching,
posting, pagination, permissions, period locks or accounting APIs.
