# R28 Step 11 — Banking Fit and Compact Sidebar

Active cache revision: `5990-r28-s11-banking-fit-sidebar`

## Banking workspace

- Bank and book panes use equal available width.
- Both tables fit inside their panes and retain vertical scrolling only.
- Column widths are budgeted for selection, date, reference, amount and status.
- Long descriptions are safely truncated with an ellipsis and retain their
  complete value in the accessible/title text.
- Book entries show a separate reference column on laptop and desktop widths.
- Narrow screens stack the panes; the optional book reference column is hidden
  on very small phones so horizontal scrolling is not introduced.

## Compact sidebar

- The existing expanded sidebar is unchanged.
- The collapsed desktop sidebar is 104 pixels wide.
- Module icons appear above persistent module names, matching the approved
  compact reference layout.
- Secondary descriptions and submenus remain hidden in compact mode.

## Safety

This step changes presentation only. It does not alter posting, matching,
pagination, permissions, banking data, audit records or accounting APIs.
