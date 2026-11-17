# R169 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R169-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `130d231c464e78eb752bbc23a60fb2d345ebfa27e7d4d0070f1052f4c54d7eb3` |
| Files | 419, all mode 0644; FILE-MANIFEST 418 entries, all verified after unpacking |
| Source commit | `f40818c`, branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R169 changes** (see `tegh-app/R169-CHANGES.md`): on a phone, pinch-zoom works, and a zoomed screen can be moved
left, right, up and down. Only style sheets changed.

**Where it was tested:** the local test host (fresh install, clock pinned to 2026-10-01 Toronto, synthetic data
only). This is not the IONOS host.

## Results

| Run | Result |
|---|---|
| Full regression on the exact ZIP: reset, all suites, end-to-end journeys, screen matrix, swipe-trap scan, upgrade (`gate-run.log`, `run-all-full-run.log`, `tail-run.log`) | **806 PASS · 7 INFO · 4 FAIL** (failures below) |
| New suite `44-r169` (`r169.json`), phone 390 × 844: pinch-zoom on the sign-in page, Dashboard, a report, Trial Balance and an invoice form; the zoomed view moves left, right and down; scrolling without zoom unchanged; no browser errors | 7/7 PASS |
| The same suite with the R168 style sheets | 5 FAIL (zoom stays at 1×), 2 PASS. So the suite detects the fault. |
| Upgrade from an R118 install with data (`upg-verify.log`) | 13/13 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 320–1920, light and dark, zoom up to 200 %, side and top navigation | 0 screens with issues; 0 phone swipe traps |

## The 4 failures come from the supplied R164-1_1 base

These are unchanged since R165:

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07:** the published terms and privacy version on the pages.
- **BS-09:** the operator details and beta conditions on the Terms page. **The owner should decide** on this one.

## Test change in this round

The Document Intake checks DI-UI1 and DI-UI2 (suite `16-r145`) failed in some runs:

- The test checked whether the upload button was visible straight after opening the screen.
- That check could see the previous copy of the page, which was then replaced, so the click waited on a hidden
  button.

R169 changes only touch-gesture settings at desktop width, which cannot hide an element or block a click. The test now
waits for the newly drawn Document Intake page before checking (`16-r145.mjs`). In this run DI-UI0–3 all pass.

## How this run was made

The test container restarted twice during gate runs. Earlier R169 logs were partly overwritten, so they are not used
here.

This result comes from one clean run:
- all suites ran from a fresh reset;
- the remaining steps (journeys, screen matrix, swipe traps, upgrade) ran through `gate-tail.sh`, which records each
  finished step so that a restart resumes rather than repeats;
- no other test or probe ran on the host at the same time.

## What each kind of evidence means

- **Automated tests:** executed on the test host above against the exact ZIP.
- **IONOS host checks:** none performed. Use `beta-ops/R164-OPERATOR-CHECKLIST.md`, which is unchanged.
- Pinch-zoom was tested in Chromium (Playwright touch events). Safari on an iPhone was not available here; please try
  it on your phone.
