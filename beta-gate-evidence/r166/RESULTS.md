# R166 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R166-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `0675874a9ac3a9c185312adffe8a357e3ab0d8bb4357bf34c89ee774dda32b78` |
| Files | 416, all mode 0644; FILE-MANIFEST 415 entries, all verified after unpacking |
| Source commit | `3f881a9`, branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R166 changes** (see `tegh-app/R166-CHANGES.md`): with Top navigation the bar uses the sidebar's colours, and
dropdown menus no longer appear off to the right before jumping into place. Only browser files changed.

**Where it was tested:** the local test host (fresh install, clock pinned to 2026-10-01 Toronto, synthetic data
only). This is not the IONOS host.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP, then screen matrix and upgrade (`run-all-full-run.log`, `finish-run.log`) | 783 PASS · 7 INFO · 5 FAIL |
| B-03 re-run on the same install (`vol-ui-rerun.txt`; `summary.txt` reflects it) | PASS, 11.3 s (limit 15 s). In the full run it measured 15.6 s; R165 measured 10.9 s. R166 changes nothing on that page in side navigation, so this was host timing. |
| After the re-run | **784 PASS · 7 INFO · 4 FAIL** (failures below) |
| New suite `41-r166` (`r166.json`): bar colours and contrast 13.5:1, active item colour, six menus painted in one place, menus still work, side navigation unchanged, no browser errors | 10/10 PASS |
| The same suite with the previous menu code | FAIL on Profile (x 1226 → 864), invoice Document (1181 → 1004) and Customers row menu (1189 → 1004). So it detects the jump. |
| R165 row-action suite `40-r165` | 11/11 PASS |
| Upgrade from an R118 install with data | 13/13 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 390–1440, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

## The 4 remaining failures come from the supplied R164-1_1 base

These are unchanged since R165. The supplied build's new marketing site replaced the product, subscriptions, terms
and privacy pages:

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07:** the published terms and privacy version on the pages.
- **BS-09:** the operator details and beta conditions on the Terms page. **The owner should decide** on this one.

## What each kind of evidence means

- **Automated tests:** executed on the test host above against the exact ZIP.
- **IONOS host checks:** none performed. Use `beta-ops/R164-OPERATOR-CHECKLIST.md`, which is unchanged.
