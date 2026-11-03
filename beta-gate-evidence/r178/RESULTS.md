# R178 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R178-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `dc601377500ff220e22c856eca23311abbb9a5422b955aad3b3bb12a6a1cef14` |
| Files | 455; FILE-MANIFEST 454 entries (verified) |
| Source commit | `7a72ea0` |

## What was checked

The ZIP was unpacked over the R177 installation on the gate host, the way an update is installed. A comparison
confirmed that only the 13 R178 files changed. Then three suites ran against it, with the synthetic gate companies:

| Suite | Result | What it covers |
|---|---|---|
| `50-r176` | 3/3 PASS | Settings › Platform Admin still lists Site & Release Settings first and opens it |
| `51-r177` | 25/25 PASS | The new dashboard: switch and access, figures against the reports, read-only, speed, browser |
| `52-r178` | 8/8 PASS | The Client Dashboard Demo (below) |

`52-r178` checks: listed second under Platform Admin and as a card on Platform Owner Home (DM-01); opens with the
sample figures, every panel loads, and the browser makes no request for company figures (DM-02); the brand-new
business view with the setup steps, empty panels and a coloured illustration (DM-03); buttons explain what they open
and stay on the demo (DM-04); phone width (DM-05); a company editor sees no entry and only a notice (DM-06); using the
demo changes no row in any of 184 tables (DM-07); it works with the New dashboard switch off (DM-08).

The first run of DM-02 read the cash figure while it was still counting up on screen ($38,695 on the way to $48,210).
The test now waits for the count to finish. The re-run passed.

## Not done

- The full regression was not re-run for R178. It last ran on R177 (`../r177/RESULTS.md`). R178 adds a demo page
  and changes no server or database code.
- Nothing was installed on the IONOS staging or production site.

Screens: `screens/r178-demo.png`, `r178-demo-new.png`, `r178-demo-phone.png`.
