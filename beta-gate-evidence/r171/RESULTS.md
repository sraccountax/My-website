# R171 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R171-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `c1741d4961360e0e605b140fffdd7dd08d77a9175b2995b83b2e4c6f8fa3fa5c` |
| Files | 430, all mode 0644; FILE-MANIFEST 429 entries, all verified after unpacking |
| Source commit | `10b5059` (`git archive 10b5059:tegh-app`), branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R171 adds** (see `tegh-app/R171-CHANGES.md`): a company feed on the Dashboard and Guided Home. It shows recent
business activity in the selected company in plain language, taken from the audit trail Tegh already keeps. Nothing
new is recorded and there is no database change.

**Where it was tested:** the local test host, fresh install from the exact ZIP, clock pinned to 2026-10-01 Toronto
(`clock-pin.txt`), synthetic data only. This is not the IONOS host.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP: reset, suites 01–39 (`run-all-gate.log`, aggregated) | **806 PASS · 7 INFO · 4 FAIL**: the 4 known base-page items, below |
| Suites 40-r165 … 44-r169 in the same run | 45/45 PASS |
| New suite `46-r171` (company feed) in the same run (`r171.json`) | **29/29 PASS** |
| Suite `45-r170` (Invoice Intelligence) in the same run (`r170-gate.json`) | 56 PASS · 6 INFO · 1 FAIL: II-12, a test fixture fault, below |
| `45-r170` re-run on the same install after the fixture fix (`r170-rerun.log`) | **57 PASS · 6 INFO · 0 FAIL** |
| End-to-end journeys; UI journeys (desk and phone) | 53/53; 22/22 PASS |
| Upgrade from an R118 install with data (`upg-verify.log`) | 13/13 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 320–1920, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

### Failures

From the supplied R164-1_1 base, unchanged since R165:

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07:** the published terms and privacy version on the pages.
- **BS-09:** the operator details and beta conditions on the Terms page. **The owner should decide** on this one.

Test fixture fault, not a product fault:

- **II-12** (one-cent rounding difference on a supplier invoice). The fixture invoice F14 was dated 2 October 2026,
  after the pinned "today" of 1 October.
  - Tegh's vendor invoice service rightly refuses an invoice dated in the future, so Tegh could not work out its own
    total, and the rounding comparison never ran.
  - The R170 gate passed this check only because its clock had drifted past 2 October (see below).
  - F14 is now dated 29 September. The regenerated fixture set differs from the previous one only in `F14.pdf` and
    `expected.json`; every other file is byte-identical. The suite then passed in full.
  - F09, F16 and F22 are also dated after 1 October. They are flagged "date in the future" and go to review, as they
    should; no check depends on drafting them.

## Corrections to earlier evidence

Found while preparing this gate. Both are fixed in the test scripts here.

1. **Clock drift.** The clock pin was not refreshed after the container restarted, so the R171 attempt before this one
   ran with the server at 5 October instead of 1 October. That attempt showed failures that depend on the date, such
   as days overdue and the cash change over 30 days; it is discarded.
   - The gate now pins the clock afresh at the start of every run (`tests/gate-resume.sh`).
   - The R170 gate's clock had also drifted, by less. That is why II-12 passed there.
2. **R170 review screen in dark mode and top navigation.** The earlier R170 suite set these through a browser setting
   the app does not read, so they were never really applied.
   - Both suites now set them on the Appearance screen and record what took effect.
   - In this gate the review screen passed with dark mode and top navigation actually applied (`r170-gate.json`,
     II-UI-*: `"applied":{"theme":"dark","nav":"top"}` and so on).
   - The correction is also noted in `beta-gate-evidence/r170/RESULTS.md`.

## Company feed: what was checked (`46-r171`, 29/29)

**Server**
- **Content:** plain-language posts, newest first, with amounts and a link to the record.
- **Privacy:** no email addresses, IP data or fingerprints are returned, and technical entries are left out.
- **Permissions:** payroll posts appear only for people who can see payroll, with no amounts; team-member names
  appear only for people who can see the team list.
- **Isolation:** another company's feed is refused (403), and signed-out requests are refused (401).
- **Behaviour:** filters, paging with no repeats, reading changes nothing, and record text is returned as data.

**On screen**
- **Seven sizes:** 1024–2560 wide, light and dark, side and top navigation, with the theme and navigation confirmed
  as applied.
  - Wide screens: the feed is open beside the page, which narrows to make room.
  - Narrower screens: a Company feed button in the page heading.
  - At every size, none of the page's buttons, links or fields lie under the feed, and there is no sideways scroll.
- **Escaping:** a customer name containing markup is shown as text and runs nothing.
- **Minimise:** the choice is kept after a refresh.
- **Live:** another person's new post arrives through the 45-second check, is marked New and is counted on the button.
- **Floating:** on a narrower screen the button opens the feed over the page; Escape closes it.
- **Opening a record:** choosing the invoice post opens the invoice; the feed is hidden on other screens and returns on
  the Dashboard.
- **Keyboard:** a post can be reached and opened with Enter; Escape minimises.
- **Guided Home:** the feed shows there and covers nothing; the Month-End Checklist shortcut stays clickable.
- **Phone (390 × 844):** the button sits in the heading and opens a full-screen sheet; no browser errors.

**Found and fixed during testing, before this gate:**
- The first version floated over the page and covered the Guided Home "Month-End Checklist" shortcut (suite 30 caught
  it).
- A minimised button fixed in the corner sat over a different control at each width.
- Both are fixed: the feed now has its own strip where there is room and a button in the heading elsewhere.

## What each kind of evidence means

- **Automated tests:** executed on the local test host against the exact ZIP.
- **IONOS host checks:** none performed. Before beta use, deploy to staging and run the host checks in
  `tegh-app/R170-OPERATIONS.md` and `beta-ops/R164-OPERATOR-CHECKLIST.md`.
- **Production:** not touched; it needs your separate authorization.
- **Browsers:** Chromium only. Safari was not available here.
