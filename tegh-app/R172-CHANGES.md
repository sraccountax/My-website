# R172 — Fixes from a full functional and screen test

R172 = R171 plus ten fixes found by a full test of the app's screens and everyday tasks. The test covered:
- desktop and phone, light and dark, side and top navigation;
- Full Accounting and Guided;
- owner, editor and viewer;
- a company with no data;
- every report and export format.

One fix stops a loss of data (item 6). There is no database change.

## Fixes

1. **Tegh Assist page.** The question box was squeezed into a column about 190 pixels wide. The page now uses one
   full-width column.
2. **Account & Access.** The Display name and Email fields were 106–132 pixels wide inside one-third-width cards.
   Forms in these cards now use one column.
3. **Create Vendor.** The supplier list failed to load (error 422) because the vendor's currency field was sent as a
   list filter. Customer and vendor directories now forward only the search and status filters.
4. **Depreciation in a closed period.** A depreciation line dated in a closed period still offered **Post**, and the
   server then refused it. Such a line now reads "Closed period · reopen it to post", and the asset's due count leaves
   it out. The server check is unchanged.
5. **Phone filter widths.** On phones, the Invoice Intelligence filter boxes, including search, use the full
   width.
6. **GIFI codes kept (data loss).** Opening the GIFI report replaced GIFI codes a person had chosen with the standard
   suggestions. It also wrote an "updated 0" audit entry on every visit.
   - Standard suggestions now fill only blank codes. The server enforces this with the new `onlyBlank` option on
     account mappings.
   - No audit entry is written when nothing changes.
7. **Company feed coverage.** These now appear in the feed:
   - invoices and vendor invoices made by recurring profiles, with the customer or supplier;
   - recurring journals;
   - new fixed assets;
   - posted depreciation;
   - completed imports.
8. **Invoice Intelligence on phones.** The queue was laid out in about twelve implicit grid columns, which squeezed
   every section. It is now one column.
9. **Wide reports.** The table's **Actions ⋮** menu holds Export…, Choose columns… and Filter columns…. On wide
   reports it sat past the right edge of the table and had to be scrolled to. It is now pinned to the right edge,
   for example on Profit and Loss by month.
10. **Export ▾ on Budgets and Fixed Assets.** The PDF and Excel choices downloaded nothing and showed "Report
    unavailable". The open menu is moved over the page, and its buttons could not find the page they belong to. They
    now resolve it.

## Files

| File | Fixes |
|---|---|
| `assets/tegh-r120.css` | 1, 2, 9 |
| `assets/tegh-professional-output-v5990.js` | 3, 10 |
| `api/advanced.php` | 4 |
| `assets/tegh-advanced-v5800.js` | 4 |
| `assets/tegh-invoice-intel-r170.css` | 5, 8 |
| `api/accounting.php` (the workspace carries each account's GIFI code) | 6 |
| `api/operations.php` (`onlyBlank`; no audit entry when nothing changes) | 6 |
| `assets/tegh-portal-v5990.js` | 6 |
| `api/activity_feed_r171.php` | 7 |
| `assets/tegh-feed-r171.js` | 7 |

The cache token in `app.html`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js` is now
`5990-r172-fixes`.

There is no database change. Accounting behaviour is unchanged: posting rules, period locks, tax and company
isolation are untouched.
