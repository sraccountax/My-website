# R185: Speed

R185 = R184 made faster. Nothing about accounting, data, permissions or switches changes.

## What was slow, and why

The figures below were measured on the test server. The test data was a synthetic company with:
- 549 invoices;
- 153 bills;
- 981 journal entries;
- 600 bank lines.

The browser network was throttled to 20 Mbit/s with 60 ms of delay, to resemble a hosted server.

| Cause | Effect in R184 |
|---|---|
| Registers (invoices, bills and others) first drew their old full table, with every row, and then replaced it with the sealed register. The page-layout code measured that full table several times | Opening the Customer Invoice & Note Register took about 3 seconds, about 1.5 s of it layout work on 16,500 page elements |
| The sign-in check read the database's foreign-key list with one joined query that MariaDB answers slowly | About 300 ms on every sign-in and reload |
| Sign-in ran these steps one after another: check the database, read the company's data, and only then download the interface | The steps waited on each other |
| 18 stylesheets and the fonts had no caching instruction | The browser re-checked each one with the server on every load |
| One style rule (`.r17-menu-panel:has(.r31-profile-preferences)`) made the browser re-check every menu panel on each page change | About 30% of the style work when moving between pages |

## What R185 changes

**Registers and reports**
- When a sealed report will replace the old table, the old table is hidden before the layout code runs, not a moment after.
- The final screen, the old table's buttons and the row actions are the same as before.

**Sign-in check**
- The foreign-key list is read with two simple queries and joined in PHP.
- The result is identical for every table: 169 tables and 416 rows checked.
- It takes about 15 ms instead of about 300 ms.

**Sign-in order**
- The company data read starts together with the database check.
- Its result is used only when the check passes with nothing to upgrade and no warning. Otherwise it is read again afterwards, as before.
- An upgrade step always waits for that early read to finish.

**Interface download**
- Interface files start downloading when the person clicks into the sign-in form, or at once when the browser already has a saved sign-in.
- They still run in the same order as before.

**Browser caching**
- Every stylesheet and script in `app.html` now carries the release token `5990-r185-speed`.
- `.htaccess` tells browsers to keep files requested with that token for a year. Each release changes the token, so a new release is always fetched fresh.
- Files requested with an older, fixed token keep their previous behaviour.
- Fonts are kept for 30 days.

**Page changes**
- The profile-menu style rule now uses a class that Tegh sets on that panel, instead of `:has()`.
- The menu looks the same: 400 px wide and scrolls.

**Package**
- Three stylesheets that nothing loads are removed:
  - `tegh-app-v5990.css`;
  - `tegh-app-v5700.css`;
  - `tegh-marketing-v5900.css`.
- Their content already lives inside `tegh-foundation-r22.css`.

## Results (test server, 3 runs each, median)

| Step | R184 | R185 | Change |
|---|---|---|---|
| Sign in to dashboard | 3.18 s | 2.26 s | −29% |
| Reload, signed in | 2.82 s | 1.71 s | −39% |
| Customer Invoice & Note Register | 2.94 s | 0.97 s | −67% |
| Vendor Invoice & Note Register | 1.53 s | 0.47 s | −69% |
| Customers / Vendors | 0.56 / 0.43 s | 0.35 / 0.23 s | −38% / −46% |
| Receivable Ageing | 0.58 s | 0.44 s | −24% |
| Bank Transaction Report | 0.57 s | 0.38 s | −34% |
| Day Book | 1.27 s | 1.12 s | −12% |
| All 19 measured steps together | 17.8 s | 11.8 s | −34% |

- Trial Balance, Balance Sheet and Match and Post vary more between runs than between releases; their ranges overlap.
- All timings are in `beta-gate-evidence/r185/RESULTS.md`.

## About the package size

The ZIP is large mainly because of the document-reading engines:
- PaddleOCR, about 27 MB;
- the Tesseract fallback, about 11 MB.

They are downloaded only when someone opens Document Intake. They are not part of sign-in or normal navigation, so they do not slow the app. Removing them would remove document reading, so they stay.

## Deploying

- Upload `.htaccess` with the other files. The caching change lives in it.
- Copies of the three removed stylesheets already on the host are harmless and may stay.
- No database change.

## Testing

| What | Result |
|---|---|
| Suite `59-r185` (12 checks) | See `beta-gate-evidence/r185/RESULTS.md` |
| Canadian before/after comparison and full gate | See `beta-gate-evidence/r185/RESULTS.md` |

Not tested on the IONOS server. IONOS may run a different database version, so its sign-in timings will differ.
