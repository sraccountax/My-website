# R168 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R168-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `d4c4378436e8b08007613139c8722db7d5a1af36cd3a38d7d11c4462314204b4` |
| Files | 418, all mode 0644 |
| Source commit | `eeb661b`, branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R168 contains** (see `tegh-app/R167-CHANGES.md` and `tegh-app/R168-CHANGES.md`):

- R167: tidier sidebar submenus. The page you are on is marked, and the Receivables and Payables trial balances are
  named AR Trial Balance and AP Trial Balance.
- R168: working in several tabs.
  - Ctrl/Cmd/Shift-click or middle-click opens a menu entry or record in a new tab.
  - Each tab keeps its own company.
  - The address names the screen and the company.
  - Refresh and Back keep the screen.

Only browser files changed. Server code, accounting and the database are unchanged.

**Where it was tested:** the local test host (fresh install, clock pinned to 2026-10-01 Toronto, synthetic data
only). This is not the IONOS host.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP, then screen matrix and upgrade (`run-all-full-run.log`, `gate-run.log`, `finish-run.log`) | 778 PASS · 7 INFO · 10 FAIL |
| Guided suite re-run alone on the same install (`guided-rerun.json`) | 23/23 PASS, including G-11, G-11b, G-14, G-15 and G-17 |
| New suite `43-r168` (`r168.json`): two tabs on two companies, Ctrl-click and middle-click to a new tab, refresh, Back, copied link, unknown company in a link, no browser errors | 9/9 PASS. It also passed 9/9 in three consecutive runs before packaging. |
| New suite `42-r167` (`r167.json`): submenu height, divider headings, no cut-off labels, AR/AP names, current-page mark, collapsed rail | 8/8 PASS |
| Earlier suites `40-r165`, `41-r166` | 11/11, 10/10 PASS |
| Screens restored on refresh, 74 menu entries and reports (`routes-before.log`, `routes-after.log`) | 68 of 74 before R168, 74 of 74 after |
| Upgrade from an R118 install with data | 13/13 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 320–1920, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

## The 10 failures in the full run

**Four come from the supplied R164-1_1 base.** They are unchanged since R165. The supplied build's marketing site
replaced the product, subscriptions, terms and privacy pages:

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07:** the published terms and privacy version on the pages.
- **BS-09:** the operator details and beta conditions on the Terms page. **The owner should decide** on this one.

**Six were timing failures on a loaded host, not product faults:**

- **B-03:** the 5,000-invoice register has a 15 s limit. It took about 16 s in this run. In the R166 investigation it
  took 11.3 s when run alone. R168 does not change that page's loading.
- **G-11, G-11b, G-14, G-15, G-17:** G-11 timed out waiting for the restore-line button. The later checks use the
  same company and depend on it. G-15's bank balance was $25.00 off because the G-11 step had not run, and G-17
  reads the same figures. The guided suite run alone on the same install passed 23/23, including these checks.

## What each kind of evidence means

- **Automated tests:** executed on the test host above against the exact ZIP.
- **IONOS host checks:** none performed. Use `beta-ops/R164-OPERATOR-CHECKLIST.md`, which is unchanged.
