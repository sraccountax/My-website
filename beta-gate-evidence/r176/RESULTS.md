# R176 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R176-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `3a872a70d54707b9312417424a52bf04bb882ceda8ce51096992c93bd4cc7a51` |
| Files | 447; FILE-MANIFEST 446 entries (all verified) |
| Source commit | `1ec74d8` |

## What was checked

The ZIP was unpacked over the R175 installation on the gate host (`https://gate.test`), as an in-place update would
be. A diff confirmed that only the R176 files changed. Then three browser and API suites ran against it:

| Suite | Result | What it covers |
|---|---|---|
| `48-r174` | 21/21 PASS | Site & Release Settings: access, saving, history, policies, sign-up, labels |
| `49-r175` | 6/6 PASS | Public-page layout, phone screenshots, Actions column |
| `50-r176` | 3/3 PASS | Settings › Platform Admin lists Site & Release Settings first and opens it (1440 and 390 wide); the Settings search finds it |

`48-r174` needs the site-settings tables empty; they held saves from the R175 gate run on this synthetic database,
so they were dropped first (the app creates them again on first use). The first attempt before that showed 3
failures for this reason (SR-02, SR-03, SR-04).

## Not done

- The full regression (all suites, upgrade from R118, screen matrix) was **not** re-run for R176. It last ran on
  R175 (`../r175/RESULTS.md`). R176 changes one menu list in one browser file plus the cache token.
- Nothing was deployed to the IONOS staging or production site.

Screenshot: `r176-settings-1440.png` (Settings › Platform Admin).
