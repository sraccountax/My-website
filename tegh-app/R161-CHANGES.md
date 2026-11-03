# R161 changes over R160: fixes from the final functional test (large companies, every mode)

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r161-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: no change.

## How it was tested
Two synthetic companies were built through the app's own API:
- **Volume Northwind Ltd:** 400 customers, 150 vendors, 5,000 invoices, 2,000 vendor invoices, about 4,900 payments, 400 journals and 3,000 imported bank lines.
- **Volume Lakeshore Inc:** 150 customers, 60 vendors, 1,500 invoices, 600 vendor invoices, about 1,500 payments, 150 journals and 1,000 bank lines.

The seeding script adds up the expected figures from the amounts it generates (13% HST on whole-dollar prices, so tax is exact); none of Tegh's own calculations are used. Every screen was then opened, in each of these combinations:
- **Modes:** Full Accounting and Guided.
- **Navigation:** side and top.
- **Themes:** light, dark, and system (dark device).
- **Screen sizes:** 1920 to 320 px wide, and 150% zoom.
- **Companies:** one company, and both companies together.

## Defects found and fixed
**1. Two saves at the same moment could fail (server error 500).**
- **What happened:** two vendor invoices, invoices or expenses saved at the same moment in one company deadlocked on voucher numbering, and one of them failed. Reproduced: 18 of 160 simultaneous saves failed.
- **Fix:** each save now takes the company's numbering lock first, so saves queue instead of colliding.
- **Result:** 160 of 160 now succeed, with no duplicate or skipped voucher number and every entry balanced. (`api/platform.php`)

**2. Registers took 45-79 seconds to open with 5,000 invoices.**
- **What happened:** the customer invoice register hit the app's own 45-second page timeout. The vendor invoice register took 14-30 seconds and Collections 8-15 seconds. The browser laid out every row several times over; each row's buttons searched the whole invoice list; and a new number or date formatter was created for every cell.
- **Fix:**
  - Tables with more than 300 rows show 250 at a time, with **Show 250 more** and **Show all**. The rows stay in the page, so search, counts, exports and printing still cover every row.
  - Each row's record is found through an index.
  - Formatters are built once.
  - The layout layer no longer builds a row-actions menu, or labels cells, for rows held back by Show more; they are done when shown.
- **Result:** on the test host, the 5,000-invoice register opens in 7-9 seconds (8-12 seconds with both companies selected) and every other screen in under 5. (`assets/tegh-portal-v5990.js`, `tegh-activity-r22.js`, `tegh-r157.css`)

**3. "The workspace changed while this request was loading" on a large company.**
- **What happened:** a background agent check runs a few seconds after a screen opens. It was treated as a bookkeeping change and cancelled every load still in progress, so Match and Post opened on "Banking Workspace Unavailable".
- **Fix:** the check only records agent findings, so it no longer cancels screens.

**4. Amounts covered by the scrollbar.**
- **What happened:** in the bank review queue (Guided and Full Accounting), the drag scrollbar sat over the right edge of the list, so amounts were cut off ("−$1,415.3").
- **Fix:** the list now keeps that strip free while the scrollbar shows. (`assets/tegh-activity-r22.js`)

**5. Theme and layout choices were lost when several companies were selected.**
- **What happened:** with two companies selected, a new theme, navigation layout, sidebar or Quick Actions choice applied at once but went back on reload. The multi-company view refuses every save as read-only, and these personal display preferences were refused with it, without a message.
- **Fix:** display preferences are not company records, so they are saved in that view too.

**6. Bank General Ledger took 151 seconds, and blocked the host backup.**
- **What happened:** with 8,500 bank lines, the complete Bank General Ledger (and the GL account ledger for any busy account) took 151 seconds to build. For every ledger line, a subquery searched all of the company's vouchers, and an OR in it stopped the database from using an index.
- **Knock-on effect:** each opening of the screen left a request running for minutes. The host backup waits for running requests, so it waited and then gave up: "3 requests were still running after 300 s; no backup was taken".
- **Fix:** the entry number now comes from two grouped lookups that give the same lowest voucher number. The output is identical, apart from the per-run reference number and time. It now takes 0.4-0.7 seconds. (`api/report_loaders_v5980.php`)
- **New checks:** the gate now times all 22 report definitions on the large company (each under 10 seconds, P-01), and checks that no request is left running after the browser checks (M-01).

**7. Smaller display fixes.**
- The sidebar count badge grows with its number; "3000" spilled out of its 22 px circle.
- Counts on Home and in the Tegh brief use thousands separators: "3,000 bank transactions to sort out", "1,578 customer invoices are overdue". (`api/insights_r144.php`)
- The bank review search box says "Search"; the old placeholder was cut off.

## Gaps found in the earlier tests
- **Guided mode was not really tested.** The Guided/Full Accounting choice is kept in the browser, and each layout run starts a fresh browser. The earlier "guided" layout runs therefore ran in Full Accounting: they showed the same 46 screens. Guided mode's menu buttons also carry no action id, so a test that opened menus by id opened nothing. The layout sweep now sets the mode inside each run, records the mode actually used, and clicks Guided menu items by name.
- **Parallel runs shared one preference.** The navigation layout (side or top) is saved on the server for the user, so parallel runs could change each other's layout. The final matrix ran one combination at a time.

## Checked and working as designed
- **The all-time Day Book for both companies (over 25,000 lines)** says "This complete report exceeds 25,000 rows. Narrow its authorized filters" instead of a cut-off report. One month (2,013 lines) opens in under 3 seconds.
- **Collection Drafts:** an "execution context destroyed" message in the screen sweep came from the test tool, not the app. Opened 8 times in a row at phone width, it never reloads the page.
- **"Customer Payments" in the menu** opens the receipt form titled "New Customer Receipt"; the form retitles itself once it is ready.

## Known limits (not changed)
- **The largest register still takes 7-12 seconds to open.** The rest of that time goes on the layout layer re-measuring the page, and on the app building the original table before the paged register replaces it. A further cut would mean rebuilding how registers load.
- **The workspace download grows with the company.** It is about 15.7 MB uncompressed for 5,000 invoices; the server sends it compressed. On a slow connection the first screen of a very large company takes longer.
- **Possible future change:** fetch registers from the server one page at a time instead of downloading the whole workspace.

## Gate tests
**29-r161:**
- **C-01:** simultaneous saves.
- **S-01:** seeding.
- **P-01:** every report definition's complete output in under 10 seconds.
- **M-01:** no request left running after the browser checks.
- **V-011…024:**
  - Trial Balance, customer and vendor balances against the GL control accounts, and P&L net income, all equal to the independently summed figures to the cent, for each company;
  - each of these reports loads in under 10 seconds.
- **B-01…08 (in the browser):**
  - P&L, Receivable Ageing and the Trial Balance for one company, and P&L and Receivable Ageing for both companies, against the same figures;
  - the 5,000-invoice register with search;
  - the Day Book limit;
  - Guided mode's review queue with 3,000 lines.

The layout sweep (`ui-sweep.mjs`) now applies Guided mode in the run itself.

## Upgrading from R160
Back up first, then upload the whole package (cache token r161-tegh). No database change.
