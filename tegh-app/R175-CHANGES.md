# R175 — Public pages: wider layout, heading sizes, phone screenshots

R175 = R174 plus changes to the public pages after the owner's review, and one phone fix in the app.

## Public pages

### Layout and type

- **Width:**
  - Content now uses up to 1,560 pixels, with 32-pixel side margins on laptop screens; it was limited to 1,160
    pixels before.
  - At 1,920 pixels wide, the side margins went from about 380 to 180 pixels.
  - The header lines up with the content.
- **Heading sizes:**

  | Text | R174 | R175 |
  |---|---|---|
  | Top heading ("Financial clarity. Without the sprawl.") | up to 62 px | at most 50 px |
  | Section headings | up to 44 px | up to 50 px (about 47 px at 1,440 wide) |
  | Card and tab headings | 20–21 px | 23–24 px |
  | Questions in the FAQ | 16 px | 18 px |
  | Policy section headings | 26 px | 30 px |
  | Menu links | 13.5 px | 15 px |
  | Small labels above headings | 13 px | 14 px |
  | Introductory text | 16–17 px | 18 px |

### Phone screenshots

On a phone, the app screenshots on the Home, Product and Features pages now show the **phone layout** of Tegh, not
the desktop layout. Desktops still show the desktop screenshots, and **Expand** opens the screenshot being shown.

- **How they were made:** captured at 390 × 844 at double resolution from a synthetic sample company,
  "Cedar & Co. (Sample)", on a separate demo site.
- **Data:** fictional customers, suppliers, employees and amounts; no real data.
- **Screens:**

  | Area | What the phone screenshot shows |
  |---|---|
  | Overview | the Dashboard |
  | Banking | the transactions of Match and Post |
  | Invoicing | a customer invoice |
  | Bills | a vendor invoice |
  | Reports | the Reports list |
  | Payroll | the employees |
  | Client Dashboard | what a client sees after signing in with the emailed code |

- **On the page:** each image is a `<picture>` with a phone source for screens up to 700 pixels wide. On phones the
  screenshot is shown from the top, at most 640 pixels tall.

## App

- **The table "Actions ⋮" column is pinned only on screens wider than 700 pixels.**
  - On phones the pinned column covered about a third of the screen and hid the amounts in registers.
  - R172 pinned it so Export could be found on wide reports. On phones, the same menu is the **Actions ⋮** button
    above the table.

## Files

| Change | Files |
|---|---|
| New | `assets/marketing-r164/m-overview.webp`, `m-matching.webp`, `m-invoicing.webp`, `m-expenses.webp`, `m-reports.webp`, `m-payroll.webp`, `m-client-dashboard.webp` |
| Changed | `assets/marketing-r164/website.css` (layout, sizes); `assets/marketing-r164/website.js` (Expand uses the image shown) |
| Changed | `index.html`, `product.html`, `features.html` (phone sources) |
| Changed | every public page and guide (stylesheet and script versions) |
| Changed | `assets/tegh-r120.css` (Actions column) |
| Cache token | `5990-r175-mobile` in `app.html`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js` |

There is no database or server change.
