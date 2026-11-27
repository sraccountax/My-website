# R180 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R180-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `ee20535dae00a81b33fd1488cf5fb23b27162de5685efb4f17042dbc81e200e8` |
| Size | 26,816,665 bytes (R179: 29,779,783) |
| Files | 423 (R179: 469); FILE-MANIFEST 422 entries, all verified on the gate host; 0 entries not 0644 |
| Source commit | `891e26c` (removal `3dcde6c`) |

R180 is R179 with 46 files removed that nothing in Tegh loads. `R180-CHANGES.md` lists every file and why it was
unused, and `RELEASE-MANIFEST.json` → `r180RemovedFiles` holds the exact list. No code changed.

## How the unused files were found

1. Every packaged file's name was searched for in every other file (release manifests and change notes excluded).
2. Each file with no match was then checked for names built in code. These look unused to a plain search but are
   loaded, so they were **kept**:
   - Appearance previews: `appearance-r25/${layout}-${theme}-${text}-${density}.webp`.
   - Import samples: built from the template name.
   - Payroll sources: `glob('payroll-catalogue/sources-*.json')`.
   - Files the host uses directly: `php.ini`, `robots.txt`, favicons, the cron entry point.
3. The remaining candidates were also searched for in the gate test scripts (none found).
4. A dry run on the R179 install, with the files still present, found that none of the 27 pages requests any of them.

## Full gate on this exact ZIP

Fresh install from the ZIP on the gate host, then every suite once, then the tail. All of this was on the test host with
synthetic companies. Nothing was installed on the IONOS staging or production site.

The container restarted after the gate had finished. The resumable runner was started again and skipped every step,
because all were already recorded as done.

| Part | Result |
|---|---|
| All suites (80 result files) | **1006 PASS**, 13 INFO, 6 FAIL (all six are known; see below) |
| R180 suite `54-r180` | **3/3 PASS**: the 46 files are not on the installed site; all 27 pages load with no 404 or 403 and request none of them; signed in, the Appearance previews (16/16) and import samples load |
| R179 suite `53-r179` | 13/13 PASS (PaddleOCR unaffected) |
| R178 suite `52-r178` | 8/8 PASS |
| Guided statements `31-r163` | All PASS, including R-47 and R-48 (each failed once in the R179 gate) |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |

### The six known failures (same as R177–R179)

| Check | Why |
|---|---|
| BS-07, BS-09 | Policy pages are not published on the freshly reset test host |
| PW-01, WR-09 | Public-page wording checks that have failed since R175 |
| DI-SCAN | Expects the old R144 reader to fail on a browser function; this test browser does not reproduce it. Not a reading-quality check |
| DD-01 | Top-bar click timing on this host; fails the same way on R173 |

## Not done

- Not installed on IONOS staging or production.
- Copies of the removed files already on the host are harmless. They can be deleted later; do not delete config,
  storage or uploads.
- Not changed: the four guide pages name a social-preview image (`assets/og/tegh-social-home-v5900.png`) that has never
  been in the package.
