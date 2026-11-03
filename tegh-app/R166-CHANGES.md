# R166 — Top navigation in the sidebar's colours; dropdowns no longer jump

R166 = R165 plus two display changes. Server code, accounting and the database are unchanged; only browser files
changed.

## Top navigation bar

With **Top navigation** (Preferences › Navigation), the bar was white with dark text, while the side navigation is
deep green with light text. The top bar now uses the sidebar's colours:

- deep green background (the sidebar's `#0d3028`, with the same gradient), light text (contrast 13.5:1);
- the current page is highlighted in the sidebar's active green (`#007558`);
- search, Create and the icon buttons are restyled to read on green;
- the same in light and dark themes and at phone width.

Menus opened from the bar keep their light panels. Side navigation is unchanged.

## Dropdowns no longer appear off to the right and then jump

Several menus are built on the browser's `<details>` element:

- Profile and Create;
- the Document menu on invoice details;
- row menus on Customers, Vendors and the registers;
- More Filters.

The browser drew the menu at its default place first, often far to the right. A moment later the menu was moved
into view next to its button, so it visibly jumped. The menu is now kept invisible from the click until it has been
placed, so it appears once, where it belongs. If a menu is not placed within 200 ms, it is shown anyway.

Measured at 1280 × 800, before → after:

| Menu | Before | After |
|---|---|---|
| Profile | drawn at x 1226, then moved to x 864 | drawn once at x 864 |
| Invoice › Document | x 1181 → 1004 | once at x 1004 |
| Customers row menu | x 1189 → 1004 | once at x 1004 |

The top bar menus, Create, report row Actions, Day Book ⋮ and notifications were already drawn in place, and still
are.

## Files

- `assets/tegh-r157.css`: top bar colours.
- `assets/tegh-activity-r22.js`: dropdown placement (next to the shared overlay manager).
- `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`: cache token `5990-r166-topbar`.
- Manifests and these notes.
