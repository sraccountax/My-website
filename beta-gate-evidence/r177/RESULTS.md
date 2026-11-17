# R177 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R177-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `6b8f08f8b9148626a5dca0c132b421ea3e86a427eb339d303263eb8b664e1fb9` |
| Files | 453; FILE-MANIFEST 452 entries (verified on the gate host) |
| Source commit | `fb3990b` |

## Full gate on this exact ZIP

The gate host was reset with the ZIP (empty database, fresh install), then every suite ran once, then the tail.
All of this was on the test host with synthetic companies. Nothing was installed on the IONOS staging or
production site.

| Part | Result |
|---|---|
| All suites (48 result files) | **982 PASS**, 13 INFO, 7 FAIL (all seven are known; see below) |
| R177 suite `51-r177` | **25/25 PASS** (after the DS-04 test fix below) |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |
| ZIP file manifest | All 452 entries OK |

### The seven known failures (same as R175 and R176)

| Check | Why |
|---|---|
| BS-07, BS-09 | Policy pages are not published on the freshly reset test host. Publishing them in Site & Release Settings fixes these. |
| PW-01, WR-09 | Public-page wording checks that also failed on R175 and R176; R177 does not change the public pages. |
| DI-I6, DI-SCAN, DD-01 | Fail the same way on R173 on this host (scanner and OCR environment, top-bar click timing). |

### DS-04 (fixed in the test, not the product)

In the first full run DS-04 failed: it expected 3 change-history rows for the New dashboard switch and found 2.
On a freshly reset database the switch already starts at "off", so the first "set to off" is not a change. The server
correctly records nothing for a save that changes nothing (`site_settings_r174.php`: an identical value returns
without writing). The test now expects the two real changes. The corrected suite was re-run on the same gate database
after the tail: 25/25 PASS (`logs/51-r177.log`; the first run is kept in `logs/51-r177-first-gate-run.json`).

## What 51-r177 checks

| Area | Checks |
|---|---|
| Switch and access | DS-01 to DS-06: off (nobody, 403), owner only (platform owner yes, company editor no), everyone; invalid values refused; the switch is kept when the stage is saved; history recorded; a company the person does not belong to is refused (403); signed out 401 |
| Accuracy | DS-10 on six synthetic companies (BC, QC with a negative bank balance, ON on cash basis, US in US dollars, R172, E2E with a loss): bank, owed to you, you owe and profit equal workspace/summary; the five ageing buckets equal the ageing report; profit and expenses equal profit and loss; the 13 forecast weeks equal the cash forecast; money in and out equal the classic chart's months |
| Read-only | DS-11: 15 summaries change no row in any of 184 tables |
| Speed | DS-12: every company answers in 23 to 38 ms on the server |
| New company | DS-13: setup steps, no figures yet |
| Browser | DU-01 to DU-09: full-width page with all panels loaded and figures matching; switch to classic and back; an editor keeps the classic page while it is owner-only; phone 390 wide; dark theme; keyboard on the money chart; new company empty states; the switch in Site & Release Settings; everyone mode for the editor |

Screens: `screens/r177-desktop.png`, `r177-phone.png`, `r177-dark.png`, `r177-new-company.png`.

## Found and fixed during R177

- Charts and pictures were squashed to 18 pixels by an app-wide icon size rule: they now set their own size.
- The new dashboard left an empty 320-pixel column on the right (the classic dashboard's Shortcuts column in the R22
  page frame): it now uses the full width, clear of the scroll bar. DU-01 checks this.
- Expense accounts with a credit balance are shown as "Refunds and credits" instead of a negative bar.
- Past weekly balances use one query instead of eight (413 ms → about 30 ms).
